<?php
/**
 * Created by PhpStorm.
 * User: 韦腾赟
 * Date: 2018/7/24
 * Time: 11:11
 */

/**
 * 解析zeta推送的包
 * @param $str 十六进制字符串
 * @return array|bool
 */
function my_unpack($str) {
    if (!is_string($str)) {
        return false;
    }
    $return['sn'] = substr($str, 2, 8); //获取设备序列号
    $byte_data    = array_map(function ($d) {
        $d = hexdec($d);
        return $d;
    }, str_split($str, 2));
    if (count($byte_data) < 11) {//每个11个字节: 包头5+数据段(>=3)+包序号3+校验位1
        //包格式错误
        return false;
    }
    $crc8 = make_crc8($byte_data, 5, -1);
    if ($crc8 !== $byte_data[count($byte_data) - 1]) {
        //校验位错误
        return false;
    }
    $head             = array_splice($byte_data, 0, 8);
    $return['charge'] = $head[0] & 0x0F;  // 0未充电 1 充电中 2 充电完成
    $return['power']  = $head[6];  //电量
    $metric_ids       = array_splice($byte_data, 0, $head[7] * 2); //截取接口和参数信息
    array_splice($byte_data, -3);
    $metric_data = [];
    while ($port_metric = array_splice($metric_ids, 0, 2)) {
        //第1个位为拓展口信息，第二个位为环境因素信息
        $port = $port_metric[0] > 0x0F ? '1' . sprintf("%02d", ($port_metric[0] & 0xF0) >> 4) . sprintf("%03d", ($port_metric[0] & 0x0F) + 1) : '000' . sprintf("%03d", $port_metric[0]);
        switch ($port_metric[1]) { //监测因素以及其值解析
            case 0x02:  //土壤温度、湿度2合1
                $val = array_splice($byte_data, 0, 2);
                $val = hex03_soil_temperature($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'soil_temperature',
                    'title'       => '土壤温度',
                    'unit'        => '℃',
                    'metric_id'   => 4,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                $val           = array_splice($byte_data, 0, 2);
                $val           = hex04_soil_humidity($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'soil_humidity',
                    'title'       => '土壤湿度',
                    'unit'        => '%',
                    'metric_id'   => 5,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x03: //土壤温度
                $val = array_splice($byte_data, 0, 2);
                $val = hex03_soil_temperature($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'soil_temperature',
                    'title'       => '土壤温度',
                    'unit'        => '℃',
                    'metric_id'   => 4,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x04://土壤湿度
                $val = array_splice($byte_data, 0, 2);
                $val = hex04_soil_humidity($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'soil_humidity',
                    'title'       => '土壤湿度',
                    'unit'        => '%',
                    'metric_id'   => 5,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x05://ph值
                $val = array_splice($byte_data, 0, 2);
                $val = hex05_ph($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'ph',
                    'title'       => 'PH值',
                    'unit'        => '',
                    'metric_id'   => 15,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x06: //光合有效度
                $val = array_splice($byte_data, 0, 2);
                $val = hex06_photosynthetic($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'photosynthetic',
                    'title'       => '光合有效辐射',
                    'unit'        => 'μmol/m2•s',
                    'metric_id'   => 14,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x07: //页面温度
                $val = array_splice($byte_data, 0, 2);
                $val = hex07_leaf_temperature($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'leaf_temperature',
                    'title'       => '叶面温度',
                    'unit'        => '℃',
                    'metric_id'   => 6,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x08: //页面湿度
                $val = array_splice($byte_data, 0, 2);
                $val = hex08_leaf_humidity($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'leaf_humidity',
                    'title'       => '叶面湿度',
                    'unit'        => '%',
                    'metric_id'   => 7,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x0D: //土壤ec
                $val = array_splice($byte_data, 0, 2);
                $val = hex0D_soil_ec($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'soil_ec',
                    'title'       => 'EC值/电导率',
                    'unit'        => 'mS/cm',
                    'metric_id'   => 11,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x0E: //二氧化碳浓度
                $val = array_splice($byte_data, 0, 2);
                $val = hex0E_co2_concentrations($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'co2_concentrations',
                    'title'       => 'CO₂浓度',
                    'unit'        => 'ppm',
                    'metric_id'   => 12,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x11: //水溶解氧和水温
                $val = array_splice($byte_data, 0, 2);
                $val = water_do($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'water_do',
                    'title'       => '水溶氧',
                    'unit'        => 'mg/L',
                    'metric_id'   => 18,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                $val           = array_splice($byte_data, 0, 2);
                $val           = hex14_water_temperature($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'water_temperature',
                    'title'       => '水温',
                    'unit'        => '℃',
                    'metric_id'   => 21,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x12: //水氨氮
                $val = array_splice($byte_data, 0, 2);
                $val = hex12_water_nh($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'water_nh',
                    'title'       => '水氨氮值',
                    'unit'        => 'mg/L',
                    'metric_id'   => 19,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x13: //水EC
                $val = array_splice($byte_data, 0, 4);
                $val = hex13_water_ec($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'water_ec',
                    'title'       => '水电导率/水EC值',
                    'unit'        => 'uS/cm',
                    'metric_id'   => 20,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x14: //水温
                $val = array_splice($byte_data, 0, 2);
                $val = hex14_water_temperature($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'water_temperature',
                    'title'       => '水温',
                    'unit'        => '℃',
                    'metric_id'   => 21,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x15: //水钾离子浓度
                $val = array_splice($byte_data, 0, 2);
                $val = hex15_potassium_ion($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'potassium_ion',
                    'title'       => '水钾离子浓度',
                    'unit'        => '',
                    'metric_id'   => 30,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x18: //温度、湿度、光照度
                $val = array_splice($byte_data, 0, 2);
                $val = hex1C_air_temperature($val); //空气温度
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'air_temperature',
                    'title'       => '空气温度',
                    'unit'        => '℃',
                    'metric_id'   => 2,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                $val           = array_splice($byte_data, 0, 2);
                $val           = hex1D_air_humidity($val); //空气湿度
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'air_humidity',
                    'title'       => '空气湿度',
                    'unit'        => '%rh',
                    'metric_id'   => 3,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                $val           = array_splice($byte_data, 0, 4);
                $val           = hex1E_illumination($val); //光照度
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'illumination',
                    'title'       => '光照度',
                    'unit'        => 'Lux',
                    'metric_id'   => 13,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x19: //水流量
                $val = array_splice($byte_data, 0, 4);
                $val = hex19_water_flow($val); //
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'water_flow',
                    'title'       => '水流量',
                    'unit'        => 'L',
                    'metric_id'   => 25,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x1A: //水压
                $val = array_splice($byte_data, 0, 4);
                $val = hex1A_water_gage($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'water_gage',
                    'title'       => '水压',
                    'unit'        => 'Mpa',
                    'metric_id'   => 26,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x1B: //水流速
                $val = array_splice($byte_data, 0, 4);
                $val = hex1B_water_speed($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'water_speed',
                    'title'       => '水流速',
                    'unit'        => 'L/h',
                    'metric_id'   => 27,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x1C: //空气温度
                $val = array_splice($byte_data, 0, 2);
                $val = hex1C_air_temperature($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'air_temperature',
                    'title'       => '空气温度',
                    'unit'        => '℃',
                    'metric_id'   => 2,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x1D: //空气湿度
                $val = array_splice($byte_data, 0, 2);
                $val = hex1D_air_humidity($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'air_humidity',
                    'title'       => '空气湿度',
                    'unit'        => '%',
                    'metric_id'   => 3,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x1E: //光照度 (默认 Plus)
                $val = array_splice($byte_data, 0, 4);
                $val = hex1E_illumination($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'illumination',
                    'title'       => '光照度',
                    'unit'        => 'Lux',
                    'metric_id'   => 13,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x20: //风速
                $val = array_splice($byte_data, 0, 2);
                $val = hex20_wind_speed($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'wind_speed',
                    'title'       => '风速',
                    'unit'        => 'm/s',
                    'metric_id'   => 10,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x21: //风向
                $val = array_splice($byte_data, 0, 2);
                $val = hex21_wind_direction($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'wind_direction',
                    'title'       => '风向',
                    'unit'        => '°',
                    'metric_id'   => 9,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x22: //大气温度
                $val = array_splice($byte_data, 0, 2);
                $val = hex22_atm_temperature($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'atm_temperature',
                    'title'       => '大气温度',
                    'unit'        => '℃',
                    'metric_id'   => 24,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x23: //大气湿度
                $val = array_splice($byte_data, 0, 2);
                $val = hex23_atm_humidity($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'atm_humidity',
                    'title'       => '大气湿度',
                    'unit'        => '%rh',
                    'metric_id'   => 23,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x24: //大气压强
                $val = array_splice($byte_data, 0, 2);
                $val = hex24_atmospheric($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'atmospheric',
                    'title'       => '大气压',
                    'unit'        => 'hPa',
                    'metric_id'   => 16,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x25: //降雨强度
                $val = array_splice($byte_data, 0, 2);
                $val = hex25_rain_intensity($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'rain_intensity',
                    'title'       => '降雨强度',
                    'unit'        => 'mm/min',
                    'metric_id'   => 22,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x26: //日降雨量
                $val = array_splice($byte_data, 0, 2);
                $val = hex26_rain_fall($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'rain_fall',
                    'title'       => '降雨量',
                    'unit'        => 'mm',
                    'metric_id'   => 8,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x27: //（气象站）光照度
                $val = array_splice($byte_data, 0, 2);
                $val = hex27_illumination($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'illumination',
                    'title'       => '光照度',
                    'unit'        => 'Lux',
                    'metric_id'   => 13,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
            case 0x28: //总辐射
                $val = array_splice($byte_data, 0, 2);
                $val = hex28_total_radiation($val);
                if ($val === false) {
                    //TODO 数据格式错误
                    return false;
                }
                $metric_data[] = [
                    'key_name'    => 'total_radiation',
                    'title'       => '总辐射',
                    'unit'        => 'W/m2',
                    'metric_id'   => 28,
                    'serial_port' => $port,
                    'metric_val'  => $val
                ];
                break;
        }
    } //循环解析每个接口的监测参数
    $return['metric_data'] = $metric_data;
    return $return;
}

/**
 * 生成校验码
 * @param $byte_data
 * @param bool $start 开始位
 * @param bool $len 长度
 * @return int
 */
function make_crc8($byte_data, $start = false, $len = false) {
    //校验
    ($start !== false && $len !== false) && $byte_data = array_slice($byte_data, 5, -1);
    $crc = 0x00; //初始值
    foreach ($byte_data as $value) {
        $crc ^= $value;
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x80) ? ($crc << 1) ^ 0x31 : $crc << 1;
        }
    }
    return $crc & 0xFF;
}


/**
 * 土壤温度
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex03_soil_temperature($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return $byte_arr[0] < 0x80 ? (($byte_arr[0] << 8) + $byte_arr[1]) * 0.1 : -(0xFFFF - (($byte_arr[0] << 8) + $byte_arr[1]) + 1) * 0.1;
}


/**
 * 土壤湿度
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex04_soil_humidity($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.1;
}


/**
 * ph 值
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex05_ph($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.01;
}


/**
 * 光合有效度
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex06_photosynthetic($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return ($byte_arr[0] << 8) + $byte_arr[1];
}


/**
 * 叶面温度
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex07_leaf_temperature($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return $byte_arr[0] < 0x80 ? (($byte_arr[0] << 8) + $byte_arr[1]) * 0.1 : -(0xFFFF - (($byte_arr[0] << 8) + $byte_arr[1]) + 1) * 0.1;
}


/**
 * 页面湿度
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex08_leaf_humidity($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.01;
}


/**
 * 土壤EC
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex0D_soil_ec($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.01;
}


/**
 * 二氧化碳浓度
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex0E_co2_concentrations($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return ($byte_arr[0] << 8) + $byte_arr[1];
}


/**
 * 水溶氧
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function water_do($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.01;
}


/**
 * 水氨氮
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex12_water_nh($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.01;
}


/**
 * 水EC
 * @param $byte_arr 4 字节
 * @return bool|float
 */
function hex13_water_ec($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 4) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 24) + ($byte_arr[1] << 16) + ($byte_arr[2] << 8) + $byte_arr[3]) * 0.01;
}


/**
 * 水温度
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex14_water_temperature($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return $byte_arr[0] < 0x80 ? (($byte_arr[0] << 8) + $byte_arr[1]) * 0.01 : -(0xFFFF - (($byte_arr[0] << 8) + $byte_arr[1]) + 1) * 0.01;
}


/**
 * 水钾离子浓度
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex15_potassium_ion($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.01;
}

/**
 * 水流量
 * @param $byte_arr
 * @return bool|int
 */
function hex19_water_flow($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 4) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return ($byte_arr[0] << 24) + ($byte_arr[1] << 16) + ($byte_arr[2] << 8) + $byte_arr[3];
}


/**
 * 水压
 * @param $byte_arr 4 字节
 * @return bool|int
 */
function hex1A_water_gage($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 4) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return ($byte_arr[0] << 24) + ($byte_arr[1] << 16) + ($byte_arr[2] << 8) + $byte_arr[3];
}


/**
 * 水流速
 * @param $byte_arr 4 字节
 * @return bool|int
 */
function hex1B_water_speed($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 4) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return ($byte_arr[0] << 24) + ($byte_arr[1] << 16) + ($byte_arr[2] << 8) + $byte_arr[3];
}

/**
 * 空气温度
 * @param $byte_arr
 * @return bool|float
 */
function hex1C_air_temperature($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return $byte_arr[0] < 0x80 ? (($byte_arr[0] << 8) + $byte_arr[1]) * 0.01 : -(0xFFFF - (($byte_arr[0] << 8) + $byte_arr[1]) + 1) * 0.01;
}


/**
 * 空气湿度
 * @param $byte_arr 2
 * @return bool|float
 */
function hex1D_air_humidity($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.01;
}


/**
 * 获取 Plush 光照度计算后的值
 * @param array $byte_arr 4 字节
 * @return string
 */
function hex1E_illumination($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 4) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    $x        = ($byte_arr[0] << 24) + ($byte_arr[1] << 16) + ($byte_arr[2] << 8) + $byte_arr[3];
    switch (true) {
        case $x >= 3500:
            $y1 = bcmul($x * $x, 0.000004476, 10);
            $y2 = bcmul($x, 1.202, 10);
            $y3 = 1130;
            return bcsub(bcadd($y1, $y2, 10), $y3, 3);
        case $x < 3500 && $x >= 2000:
            $y1 = bcmul($x * $x, 0.000004476, 10);
            $y2 = bcmul($x, 0.857, 10);
            $y3 = 79;
            return bcadd(bcadd($y1, $y2, 10), $y3, 3);
        default:
            $y1 = bcmul($x * $x, -0.0000227, 10);
            $y2 = bcmul($x, 0.9599, 10);
            return bcadd($y1, $y2, 3);
    }
}


/**
 * 获取风速
 * @param array $byte_arr
 * @return string
 */
function hex20_wind_speed(array $byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.1;
}

/**
 * 风向
 * @param $byte_arr 2 字节
 * @return bool|int
 */
function hex21_wind_direction($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return ($byte_arr[0] << 8) + $byte_arr[1];
}

/**
 * 大气温度
 * @param $byte_arr 2 字节
 * @return float
 */
function hex22_atm_temperature($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return $byte_arr[0] < 0x80 ? (($byte_arr[0] << 8) + $byte_arr[1]) * 0.1 : -(0xFFFF - (($byte_arr[0] << 8) + $byte_arr[1]) + 1) * 0.1;
}

/**
 * 大气湿度
 * @param $byte_arr
 * @return bool|float
 */
function hex23_atm_humidity($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.1;
}

/**
 * 大气压强
 * @param $byte_arr
 * @return bool|float
 */
function hex24_atmospheric($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.01;
}

/**
 * 降雨强度
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex25_rain_intensity($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.1;
}

/**
 * 日降雨量
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex26_rain_fall($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 0.1;
}

/**
 * (气象站)光照度
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex27_illumination($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return (($byte_arr[0] << 8) + $byte_arr[1]) * 10;
}

/**
 * 总辐射
 * @param $byte_arr 2 字节
 * @return bool|float
 */
function hex28_total_radiation($byte_arr) {
    if (!is_array($byte_arr)) {
        return false;
    }
    if (count($byte_arr) !== 2) {
        return false;
    }
    $byte_arr = array_values($byte_arr);
    return ($byte_arr[0] << 8) + $byte_arr[1];
}

/**
 * 简单的日志输出
 * @param $str
 */
function swoole_log($str) {
    echo date('Y-m-d H:i:s') . ' | ' . $str . "\r\n";
}


function curl($url, $data, $httpheader = array()) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    if ($httpheader) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}