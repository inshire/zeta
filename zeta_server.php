<?php
require '/data/server/zeta/config.php'; //配置文件
class zeta_server {
    //数据库相关
    protected $_pdo = null;

    //zeta相关
    protected $_aq_time; //zeta平台的时间

    //redis key
    protected $_redis                = null;
    protected $_alarm_timeout        = 3600; //一小时内同样的报警，只会触发一次推送
    protected $_pond_alarm_cache_key = 'aq:mt:alm:'; //key = aq:mt:alm:$pond_id:$metric_id, value = 1 or 2，1 低于阈值，2 高于阈值
    protected $_device_key           = 'aq:sn:';    //缓存设备信息

    //服务器时间相关
    protected $_curr_time;
    protected $_curr_year;
    protected $_curr_month;
    protected $_curr_day;
    protected $_curr_hour;

    public function __construct() {
        date_default_timezone_set('Asia/Shanghai');//时区设置
        $http_server = new swoole_http_server('127.0.0.1', 9500);
        $http_server->on('request', function ($request, $response) use ($http_server) {
            if (empty($request->get['cmd'])) {
                return;
            }
            $http_server->reload($request->get['cmd']); //重启
        });
        $http_server->on('WorkerStart', function ($serv, $id) {
            require '/data/server/zeta/func.php'; //CONF辅助函数
            if ($id === 0) {
                $client = new Mosquitto\Client(ZETA_CLIENTID);
                $client->setCredentials(ZETA_USER, ZETA_PWD);
                $client->onConnect(function ($r, $message) {

                });
                $client->onDisconnect(function () {

                });
                $client->onSubscribe(function () {

                });
                $client->onMessage(function ($message) use ($serv) {
                    $topic = substr(strrchr($message->topic, '/'), 1);
                    if ($topic !== 'reportuploadData') {
                        return;
                    }
                    $payload = json_decode($message->payload, true);
                    if (!$payload) {
                        //TODO 格式错误
                        swoole_log('error,$payload = ' . $payload);
                        return;
                    }
                    $hexstr = $payload['msgParam']['data']; //数据
                    $res    = my_unpack($hexstr);
                    if (!is_array($res)) {
                        swoole_log('error,$hexstr = ' . $hexstr);
                        return;
                    }
                    $time  = time();
                    $task1 = [
                        'time'        => $time,
                        'task_name'   => 'task_thres_check',
                        'task_params' => [
                            $res['sn'],
                            $res['metric_data']
                        ]
                    ];
                    $task2 = [
                        'time'        => $time,
                        'task_name'   => 'task_insert_metric_data',
                        'task_params' => [
                            $res['sn'],
                            $res['metric_data'],
                        ]
                    ];
                    $task3 = [
                        'time'        => $time,
                        'task_name'   => 'task_update_device',
                        'task_params' => [
                            $res['sn'],
                            [
                                'charge'      => $res['charge'],
                                'power'       => $res['power'],
                                'last_active' => $time,
                            ]
                        ]
                    ];
                    $serv->task($task1);
                    $serv->task($task2);
                    $serv->task($task3);
                });
                try {
                    $client->connect(ZETA_HOST, ZETA_PORT, ZETA_TIMEOUT);       //连接对方服务器
                } catch (Exception $e) {
                    //记录日志
                    exit('connect error and exit!');
                }
                $client->onLog(function () {
                });
                $client->subscribe(ZETA_APIKEY . '/+/+/+/reportuploadData', ZETA_QOS);
                $client->loopForever();
            }
        });
        $http_server->on('task', [$this, 'task']);
        $http_server->on('finish', [$this, 'task']);
        $http_server->set([
            'worker_num'      => 2,
            'task_worker_num' => 10,
            'daemonize'       => 1,
            'log_file'        => SWOOLE_LOG_FILE,
        ]);
        $http_server->start();
    }

    public function task($serv, $task_id, $src_worker_id, $task_data) {

        //设置服务器时间
        $this->_curr_time  = $task_data['time'];
        $this->_curr_year  = date('Y', $this->_curr_time);
        $this->_curr_month = date('n', $this->_curr_time); //月份没有前导0
        $this->_curr_day   = date('j', $this->_curr_time); //第几天，没有前导0
        $this->_curr_hour  = date('G', $this->_curr_time);//小时，没有前导0

        //业务处理
        switch ($task_data['task_name']) {
            case 'task_thres_check':
                list($sn, $metrics) = $task_data['task_params'];
                $this->task_thres_check($sn, $metrics);
                break;
            case 'task_insert_metric_data':
                list($sn, $metrics) = $task_data['task_params'];
                $this->task_insert_metric_data($sn, $metrics);
                break;
            case 'task_update_device':
                list($sn, $update) = $task_data['task_params'];
                $this->task_update_device($sn, $update);
        }

        //收尾
        $this->_pdo   = null;
        $this->_redis = null;
    }


    /**
     * 阈值检查
     * @param $sn 设备sn
     * @param $metrics 监测的指数
     * @return bool
     */
    public function task_thres_check($sn, $metrics) {
        if (empty($metrics)) {
            return;
        }
        if (!$this->pdo()) {
            return false;
        }
        // $sql         = "
        //     SELECT
        //         t1.`id`,
        //         t1.`base_id`,
        //         t1.`region_id`,
        //         t1.`ponds_id`,
        //         t1.`device_name`,
        //         t2.`name` AS base_name,
        //         t3.`name` AS region_name,
        //         t4.`name` AS pond_name
        //     FROM dv_device AS t1,bs_base AS t2,re_region AS t3,pn_ponds AS t4
        //     WHERE t1.`sn` = '{$sn}'
        //     AND t2.`id` = t1.`base_id`
        //     AND t3.`id` = t1.`region_id`
        //     AND t4.`id` = t1.`ponds_id`
        //     LIMIT 1";
        // $device_info = $this->db_query($sql); //获取设备信息
        $device_info = $this->get_device_register($sn);
        if (!$device_info) {
            return false;
        }
        //获取阈值
        $thres = $this->db_find(['pond_id' => $device_info['ponds_id']], 'metric_range,stime,etime', 'pn_pond_threshold', 2); //当前版本只有2个阈值场景
        if (!$thres) {
            return false;
        }
        $thres        = array_map(function ($row) {
            $row['metric_range'] = json_decode($row['metric_range'], true);
            return $row;
        }, $thres);
        $curr_Hi      = date('H:i', $this->_curr_time);
        $period_thres = false;
        //获取在时间范围内的阈值
        foreach ($thres as $row) {
            $flag = false;
            if ($row['stime'] && $row['etime']) {
                $flag = trim($row['stime']) <= $curr_Hi && $curr_Hi <= trim($row['etime']);
            } elseif ($row['stime']) {
                $flag = $curr_Hi >= trim($row['stime']);
            } elseif ($row['etime']) {
                $flag = $curr_Hi <= trim($row['etime']);
            }
            if ($flag) {
                $period_thres = $row['metric_range']; //只获取
                break;
            }
        }
        if (!$period_thres) { //没有生效的阈值
            return false;
        }
        $alarm_data   = [];
        $alarm_msg    = [];
        $alarm_metric = []; //本次触发的报警的监测因素
        //遍历检查阈值
        foreach ($metrics as $row) {
            if (!isset($period_thres[$row['key_name']])) {
                continue; //没有阈值直接跳过
            }
            $less_than    = false;
            $greater_than = false;
            if ($period_thres[$row['key_name']][0] !== '') {//阈值下限判断
                $less_than = bccomp($row['metric_val'], $period_thres[$row['key_name']][0], 3) < 0;// bccomp -1 小于 0 等于 1 大于
            }

            if ($period_thres[$row['key_name']][1] !== '') {//阈值上限判断
                $greater_than = bccomp($row['metric_val'], $period_thres[$row['key_name']][1], 3) > 0;
            }
            $alarm_status = $this->get_pond_alarm($device_info['ponds_id'], $row['metric_id']); //获取目前报警的状态，false 没有报警状态，1 目前处于偏低报警 2 ... 偏高报警
            if (!$less_than || !$greater_than) {
                //存在阈值超出的情况
                $alarm_metric[] = $row['metric_id']; //考虑一个设备接多个相同的传感器
                //保存每次阈值超出记录
                $level        = $less_than ? 1 : 2; //阈值偏高或偏低
                $alarm_data[] = [
                    'device_id'   => $device_info['id'],
                    'metric_id'   => $row['metric_id'],
                    'serial_port' => $row['serial_port'],
                    'year'        => $this->_curr_year,
                    'month'       => $this->_curr_month,
                    'day'         => $this->_curr_day,
                    'hour'        => $this->_curr_hour,
                    'val_level'   => $level,
                    'ctime'       => $this->_curr_time,
                ];
                if ($alarm_status && $alarm_status == $level) {
                    continue;
                }
                $this->set_pond_alarm($device_info['ponds_id'], $row['metric_id'], $level); //设置新的报警状态
                $alarm_msg[] = [
                    'base_id'   => $device_info['base_id'], //基地
                    'device_id' => $device_info['id'],      //设备ID
                    'content'   => json_encode([ //报警类型
                        'metric'    => $row['title'],       //属性名称
                        'value'     => $row['metric_val'],  //现在的值
                        'unit'      => $row['unit'],        //属性单位
                        'val_level' => $level,              //报警类型 1 低于 2 高于
                        're_name'   => !empty($device_info['region_name']) ? $device_info['region_name'] : '-',     //区域名称
                        'pn_name'   => !empty($device_info['pond_name']) ? $device_info['pond_name'] : '-',          //池塘名称
                    ], JSON_UNESCAPED_UNICODE),
                    'ctime'     => $this->_curr_time,
                ];
            } elseif (!in_array($row['metric_id'], $alarm_metric) && $alarm_status) {
                //阈值正常且之前有报警
                //1移除报警
                //2推送报警解除消息
                $this->remove_pond_alarm($device_info['ponds_id'], $row['metric_id']);
                //添加解除报警信息
                $alarm_msg[] = [
                    'base_id'   => $device_info['base_id'],
                    'device_id' => $device_info['id'],
                    'content'   => json_encode([
                        'metric'    => $row['title'],           //属性名称
                        'value'     => $row['metric_val'],      //当时的值
                        'unit'      => $row['unit'],            //属性单位
                        'val_level' => '0', //报警类型 1 低于 2 高于 0 正常
                        're_name'   => !empty($device_info['region_name']) ? $device_info['region_name'] : '-',     //区域名称
                        'pn_name'   => !empty($device_info['pond_name']) ? $device_info['pond_name'] : '-',          //池塘名称
                    ], JSON_UNESCAPED_UNICODE),
                    'ctime'     => $this->_curr_time,
                ];
            }
        }
        if ($alarm_data) { //db 超出阈值的记录
            $this->db_insert($alarm_data, 'dv_monitor_alert_record');
        }
        if ($alarm_msg) { //db 保存报警消息
            $this->db_insert($alarm_msg, 't_message_alarm');
            var_dump($device_info);
            $to_push = [
                'base_id'     => $device_info['base_id'],
                'msg_type'    => '1',
                'msg_content' => '有新的报警信息！'
            ];
            $res     = curl('127.0.0.1:9528', json_encode($to_push)); //websocket 推送,推送json
            //TODO 推送APP
        }
    }


    protected function task_insert_metric_data($sn, $metrics) {
        if (empty($metrics)) {
            return;
        }
        // $metrics 格式
        // [
        //     'key_name'    => 'water_flow',
        //     'title'       => '水流量',
        //     'unit'        => 'L',
        //     'metric_id'   => 25,
        //     'serial_port' => $port,
        //     'metric_val'  => $val
        // ];

        //数据表结构
        // `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        // `device_id` int(11) NOT NULL,
        // `metric_id` smallint(32) unsigned NOT NULL DEFAULT '0' COMMENT '度量标准标识：如 1（温度），2（湿度），3（光照）……',
        // `serial_port` char(6) NOT NULL DEFAULT '0' COMMENT '拓展口标记：0 1 2 ……',
        // `metric_val` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '度量标准的值',
        // `year` smallint(5) unsigned NOT NULL DEFAULT '0' COMMENT '年份',
        // `month` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '月份',
        // `day` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '每个月中的第几天',
        // `hour` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '小时',
        // `ctime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '添加记录的时间戳',
        if (!$this->pdo()) {
            return false;
        }
        $device_info = $this->get_device_register($sn);
        if (!$device_info) {
            return false;
        }
        foreach ($metrics as &$row) {
            $row['device_id'] = $device_info['id'];
            $row['year']      = $this->_curr_year;
            $row['month']     = $this->_curr_month;
            $row['day']       = $this->_curr_day;
            $row['hour']      = $this->_curr_hour;
            $row['ctime']     = $this->_curr_time;
            unset($row['key_name'], $row['title'], $row['title'], $row['unit']);
        }
        $res = $this->db_insert($metrics, 'dv_monitor_data');
    }

    protected function task_update_device($sn, $update) {
        if (empty($update)) {
            return;
        }
        if (!$this->pdo()) {
            return false;
        }
        $device_info = $this->get_device_register($sn);
        if (!$device_info) {
            return false;
        }
        $this->db_update($update, ['id' => $device_info['id']], 'dv_device');
    }




    //task helper start


    // db start

    /**
     * 数据库连接
     * @return bool
     */
    protected function pdo() {
        if ($this->_pdo) {
            return $this->_pdo;
        }
        try {
            $this->_pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
                DB_USER,
                DB_PWD,
                [
                    PDO::ATTR_TIMEOUT => DB_TIMEOUT,
                ]
            );
        } catch (PDOException $e) {
            swoole_log('type:error,info:' . $e->getMessage());
            return false;
        }
        return true;
    }


    /**
     * 查询一条语句
     * @param $sql
     * @param bool $multi
     * @return bool
     */
    protected function db_query($sql, $multi = false) {
        $stmt = $this->_pdo->query($sql);
        if (!$stmt) {
            return false;
        }
        return $multi ? $stmt->fetchAll(PDO::FETCH_ASSOC) : $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * 单表查询一/多条记录
     * @param $cond
     * @param $field
     * @param $tb_name
     * @param int $limit
     * @return bool
     */
    protected function db_find($cond, $field, $tb_name, $limit = 1) {
        $where = '';
        foreach ($cond as $key => $val) {
            $where .= '`' . $key . '` = "' . $val . '" AND ';
        }
        $where = trim($where, ' AND');
        $sql   = 'SELECT ' . $field . ' FROM ' . $tb_name . ' WHERE ' . $where . ' LIMIT ' . $limit;
        $stmt  = $this->_pdo->query($sql);
        if ($stmt === false) {
            swoole_log('error:sql,info:' . $sql);
            return false;
        }
        return $limit > 1 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : $stmt->fetch(PDO::FETCH_ASSOC);
    }


    /**
     * 插入数据
     * @param $data
     * @param $tb_name
     * @return bool
     */
    protected function db_insert($data, $tb_name) {
        if (count($data) != count($data, true)) {
            $field = array_keys(current($data));
            $field = array_map(function ($d) {
                return '`' . $d . '`';
            }, $field);
            $field = '(' . implode(',', $field) . ')';
            $rows  = '';
            foreach ($data as $row) {
                $vals = '';
                foreach ($row as $value) {
                    $vals .= "'" . $value . "',";
                }
                $vals = '(' . trim($vals, ',') . ')';
                $rows .= $vals . ',';
            }
            $rows = trim($rows, ',');
            $sql  = 'INSERT INTO ' . $tb_name . ' ' . $field . ' VALUES ' . $rows;
        } else {
            $field = '';
            $vals  = '';
            foreach ($data as $key => $val) {
                $field .= '`' . $key . '`,';
                $vals  .= "'" . $val . "',";
            }
            $field = '(' . trim($field, ',') . ')';
            $vals  = '(' . trim($vals, ',') . ')';
            $sql   = 'INSERT INTO ' . $tb_name . ' ' . $field . ' VALUES ' . $vals;
        }
        $count = $this->_pdo->exec($sql);
        if ($count === false) {
            swoole_log('error:sql,info:' . $sql);
            return false;
        }
        return $count;
    }


    /**
     * 更新记录
     * @param $data
     * @param $cond
     * @param $table
     * @return bool
     */
    protected function db_update($data, $cond, $table) {
        $update = '';
        $where  = '';
        foreach ($data as $key => $val) {
            $update .= '`' . $key . '` = ' . '"' . $val . '",';
        }
        foreach ($cond as $key => $val) {
            $where .= '`' . $key . '` = ' . '"' . $val . '" AND ';
        }
        $update = trim($update, ',');
        $where  = trim($where, ' AND');
        $sql    = 'UPDATE ' . $table . ' SET ' . $update . ' WHERE ' . $where;
        $count  = $this->_pdo->exec($sql);
        if ($count === false) {
            swoole_log('error:sql,info:' . $sql);
            return false;
        }
        return $count;
    }

    //redis start

    /**
     * redis 单例
     * @return null|Redis
     */
    protected function redis() {
        if (!$this->_redis) {
            $this->_redis = new Redis();
            $this->_redis->connect(REDIS_HOST, REDIS_PORT);
            REDIS_PWD && $this->_redis->auth(REDIS_PWD);
        }
        return $this->_redis;
    }

    /************** 报警相关 start ************/
    /**
     * 获取池塘监测因素报警状态 false or 1 or 2
     * @param $pond_id
     * @param $metric_id
     * @return bool|string
     */
    protected function get_pond_alarm($pond_id, $metric_id) {
        return $this->redis()->get($this->_pond_alarm_cache_key . $pond_id . ':' . $metric_id);
    }

    /**
     * 设置池塘的监测因素报警状态
     * @param $pond_id
     * @param $metric_id
     * @param $level
     * @return bool
     */
    protected function set_pond_alarm($pond_id, $metric_id, $level) {
        return $this->redis()->setex($this->_pond_alarm_cache_key . $pond_id . ':' . $metric_id, $this->_alarm_timeout, $level);
    }

    /**
     * 移除池塘监测因素报警状态
     * @param $pond_id
     * @param $metric_id
     * @return int
     */
    protected function remove_pond_alarm($pond_id, $metric_id) {
        return $this->redis()->del($this->_pond_alarm_cache_key . $pond_id . ':' . $metric_id);
    }
    /************** 报警相关 end ************/


    /************** 设备相关 start ************/
    protected function get_device_register($sn) {
        $res = $this->redis()->get($this->_device_key . $sn);
        if ($res === '0') {
            return false;
        }
        if ($res) {
            return json_decode($res, true);
        }
        $sql         = "
            SELECT
                t1.`id`,
                t1.`base_id`,
                t1.`region_id`,
                t1.`ponds_id`,
                t1.`device_name`,
                t2.`name` AS base_name,
                t3.`name` AS region_name,
                t4.`name` AS pond_name
            FROM dv_device AS t1,bs_base AS t2,re_region AS t3,pn_ponds AS t4
            WHERE t1.`short_sn` = '{$sn}'
            AND t1.`ctime` != 0
            AND t2.`id` = t1.`base_id`
            AND t3.`id` = t1.`region_id`
            AND t4.`id` = t1.`ponds_id`
            LIMIT 1";
        $device_info = $this->db_query($sql); //获取设备信息
        if (!$device_info) {
            swoole_log('error:unregistered,short_sn:' . $sn);
            $this->set_device_register($sn, 0); //未注册的设备
            return false;
        }
        $this->set_device_register($sn, json_encode($device_info)); //记录设备信息
        return $device_info;
    }

    protected function set_device_register($sn, $val) {
        $this->redis()->set($this->_device_key . $sn, $val);
    }
    /************** 设备相关 end ************/

}

$serv = new zeta_server();

