#!/bin/bash
if [ "$1" = "" -o "$2" = "" ];then
        exit
fi
phppath="/usr/local/php-7.0.2/bin/php"
#filepath=$(pwd)"/" 获取脚本路径，不能使用pwd
filepath=$(cd `dirname $0`; pwd)"/"
filename="$filepath$1"
if [ ! -f $filename ];then
        exit
fi

# start
start() {
        process_num=$(ps -ef|grep $filename|grep -v grep|wc -l)
        if [ $process_num != "0" ];then
                exit
        else
                $($phppath $filename)
        fi
}

# stop
stop() {
        process_num=$(ps -ef|grep $filename|grep -v grep|wc -l)
        if [ $process_num != "0" ];then
                ps -ef|grep $filename|grep -v grep|cut -c 9-15|xargs kill -9
        else
                exit
        fi
}
case "$2" in
start)
start
;;
stop)
stop
;;
restart)
stop
start
;;
*)
exit
esac
