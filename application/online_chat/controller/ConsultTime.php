<?php
namespace app\online_chat\controller;
use think\Db;
use think\facade\Request;
use think\Controller;
use app\online_chat\serverApi\WebsocketServerApi;
use app\online_chat\serverApi\ModelApi;
use app\online_chat\serverApi\ChatException;

class ConsultTime extends Controller{
    /**
     * 获取咨询聊天计时
     * $_GET[to_id] 和谁聊天
     */
    public function getConsultTime(){
        if( empty($_GET['to_id']) ){
            returnMsg(100,'to_id参数不能为空！');
        }
        $user_type = session('chat_user.user_type');
        if( $user_type == 0 ){
            $uid = session('chat_user.uid');
            $to_id = $_GET['to_id'];
        }elseif( $user_type == 2 ){
            $uid = $_GET['to_id'];
            $to_id = session('chat_user.uid');
        }else{
            returnMsg(100,'用户类型不正确！');
        }
        //var_dump($uid,$to_id);exit; 
        $sql = 'select * from chat_consult_time
                    where uid = ? and to_id=? and soft_delete=0 and (free_duration>0 || duration_count>0) and status < 3
                        order by free_duration desc,duration_count asc, id asc limit 100';
        $consultTimes = db::query($sql,[$uid,$to_id]);

        $freeConsult = 0;
        $sql = 'select * from chat_consult_time
                    where uid = ? and to_id=? and soft_delete=0 and free_duration>0 
                        order by id desc limit 1';
        $tmpConsultTimes = db::query($sql,[$uid,$to_id]);
        if( !empty($tmpConsultTimes) ){
            if( $tmpConsultTimes[0]['ctime'] < time() - 2*24*3600 ){
                $freeConsult=1;
            }
        }else{
            $freeConsult = 1;
        }
        $showFreeButton = 0;
        if( empty($consultTimes) ){
            $consult_time = [];
        }else{
            $consult_time = $consultTimes[0];
        }
        foreach( $consultTimes as $consultTime ){
            if( $consult_time['status'] == '1' ){
                $consult_time = $consultTime;
                break;
            }
        }
        if( isset($consult_time['soft_delete']) ){
            unset($consult_time['soft_delete']);
        }
        returnMsg(200,'',[
            'consult_time'=>$consult_time,
            'freeConsult'=>$freeConsult
        ]);
    }
    /**
     * 添加免费咨询
     * $_POST[to_id] 和谁聊天
     */
    public function addFreeConsult(){
        /**
         * @var $strategy 添加免费咨询接口策略
         * default使用当前接口
         * user用户自定义接口
         */
        $strategy = 'default';
        if( $strategy != 'default' ){
            returnMsg(100,'请使用用户自定义添加免费咨询接口');
        }
        if( empty($_POST['to_id']) ){
            returnMsg(100,'to_id参数不能为空！');
        }
        $consultServer = db::table('chat_user')->where('uid',$_POST['to_id'])->where('user_type',2)->where('soft_delete=0')->find();
        if( empty($consultServer) ){
            returnMsg(100,'to_id参数不正确！');
        }
        $free_time = 300;
        $uid = session('chat_user.uid');
        $sql = 'select * from chat_consult_time
                    where uid = ? and to_id=? and soft_delete=0 and free_duration>0 
                        order by id desc limit 1';
        $consultTimes = db::query($sql,[$uid,$_POST['to_id']]);
        if( !empty($consultTimes) ){
            if( $consultTimes[0]['ctime'] > time() - 2*24*3600 ){
                returnMsg(100,'您已在两天里面领取免费咨询了',[
                    'consult_time'=>$consultTimes[0]
                ]);
            }
        }
        $id  = db::table('chat_consult_time')->insertGetId([
            'uid'=>$uid,
            'to_id'=>$_POST['to_id'],
            'status'=>0,
            'duration_count'=>0,
            'duration'=>0,
            'free_duration_count'=>$free_time,
            'free_duration'=>$free_time,
            'total_duration'=>$free_time,
            'delayed_duration_total'=>0,
            'delayed_num'=>0,
            'ctime'=>time(),
            'soft_delete'=>0
        ]);
        $consult_time = db::table('chat_consult_time')->where('id',$id)->find();
        $chatSession = db::table('chat_session')->where('uid',$_POST['to_id'])->where('to_id',$uid)->where('chat_type=3')->find();
        if( empty($chatSession) ){
            db::table('chat_session')->insert([
                'uid'=>$_POST['to_id'],
                'to_id'=>$uid,
                'chat_type'=>3,
                'last_time'=>0,
                'soft_delete'=>0
            ]);
        }
        returnMsg(200,'',[
            'consult_time'=>$consult_time
        ]);
    }
    /**
     * 新增计时
     * $_POST[to_id] 咨询师id
     */
    public function addConsult(){
        /**
         * @var $strategy 新增计时接口策略
         * default使用当前接口
         * user用户自定义接口
         */
        $strategy = 'default';
        if( $strategy != 'default' ){
            returnMsg(100,'请使用用户自定义新增计时接口');
        }
        if( empty($_POST['to_id']) ){
            returnMsg(100,'to_id参数不能为空！');
        }
        $consultServer = db::table('chat_user')->where('uid',$_POST['to_id'])->where('user_type',2)->where('soft_delete=0')->find();
        if( empty($consultServer) ){
            returnMsg(100,'to_id参数不正确！');
        }
        $duration = 1800;
        $uid = session('chat_user.uid');
        try{
            $id = ModelApi::addConsult($uid,$_POST['to_id'],$duration);
        }catch( \Exception $e ){
            if( $e instanceof ChatException ){
                returnMsg(100,$e->getMessage());
            }else{
                throw $e;
            }
        }
        $consult_time = db::table('chat_consult_time')->where('id',$id)->find();
        returnMsg(200,'',[
            'consult_time'=>$consult_time
        ]);
    }
    /**
     * 延时
     * $_POST[consult_time_id] 咨询id
     */
    public function delayedDuration(){
        /**
         * @var $strategy 延时接口策略
         * default使用当前接口
         * user用户自定义接口
         */
        $strategy = 'default';
        if( $strategy != 'default' ){
            returnMsg(100,'请使用用户自定义咨询延时接口');
        }
        if( empty($_POST['consult_time_id']) ){
            returnMsg(100,'consult_time_id参数不能为空！');
        }
        $uid =  session('chat_user.uid');
        $duration = 1800;
        try{
            ModelApi::delayedDuration($uid,$_POST['consult_time_id'],$duration);
        }catch( \Exception $e ){
            if( $e instanceof ChatException ){
                returnMsg(100,$e->getMessage());
            }else{
                throw $e;
            }
        }
        returnMsg(200,'延时成功！');
    }
    /**
     * 开启咨询
     * $_POST[consult_time_id] 咨询id
     */
    public function startConsult(){
        if( empty($_POST['consult_time_id']) ){
            returnMsg(100,'consult_time_id参数不能为空！');
        }
        $uid =  session('chat_user.uid');
        $consult_time = db::table('chat_consult_time')->where('id',$_POST['consult_time_id'])->where('uid',$uid)->find();
        if( empty($consult_time) ){
            returnMsg(200,'consult_time_id参数不正确！');
        }
        $consultServer = db::table('chat_user')->where('uid',$consult_time['to_id'])->where('user_type',2)->where('soft_delete=0')->find();
        if( empty($consultServer) ){
            returnMsg(100,'没有找到咨询师');
        }
        if( $consultServer['online'] == '0' ){
            returnMsg(100,'咨询师不在线！',[
                'consult_server_online'=>0
            ]);
        }
        if( $consult_time['status'] == '0' ){
            
        }elseif( $consult_time['status'] == '1' ){
            returnMsg(100,'已经开启咨询了的！');
        }elseif( $consult_time['status'] == '2' ){

        }elseif( $consult_time['status'] == '3' ){
            returnMsg(100,'咨询已完成了的！');
        }else{
            returnMsg(100,'状态不正确！');
        }
        $consult_time['status'] = '1';
        WebsocketServerApi::startConsult($consult_time);
        session('start_consult_time',time());
        returnMsg(200,'开启咨询成功！');

    }
    /**
     * 暂停咨询
     * $_POST[consult_time_id] 咨询id
     */
    public function suspendConsult(){
        if( empty($_POST['consult_time_id']) ){
            returnMsg(100,'consult_time_id参数不能为空！');
        }
        if( time() - session('start_consult_time') < 3 ){
            returnMsg(100,'你操作太频繁，请稍后操作！');
        }
        $uid =  session('chat_user.uid');
        $consult_time = db::table('chat_consult_time')->where('id',$_POST['consult_time_id'])->where('uid',$uid)->find();
        if( empty($consult_time) ){
            returnMsg(200,'consult_time_id参数不正确！');
        }
        if( $consult_time['status'] == '0' ){
            returnMsg(100,'还未开始咨询！');
        }elseif( $consult_time['status'] == '1' ){

        }elseif( $consult_time['status'] == '2' ){
            returnMsg(100,'已是暂停状态！');
        }elseif( $consult_time['status'] == '3' ){
            returnMsg(100,'咨询已完成了的！');
        }else{
            returnMsg(100,'状态不正确！');
        }
        db::table('chat_consult_time')->where('id',$_POST['consult_time_id'])->update([
            'status'=>2
        ]);
        WebsocketServerApi::suspendConsult($_POST['consult_time_id']);
        returnMsg(200,'暂停咨询成功！');
    }
}