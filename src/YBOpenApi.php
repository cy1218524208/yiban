<?php
namespace likecy\yiban;
/**
 * Created by PhpStorm.
 * User: chenyun
 * Date: 2018/3/6
 * Time: 14:31
 */

class YBOpenApi
{
    const YIBAN_OPEN_URL = "https://openapi.yiban.cn/";

    private static $mpInstance = NULL;

    private $_config = array(
        'appid' => '',
        'seckey' => '',
        'token' => '',
        'backurl' => ''
    );

    private $_instance = array();

    /**
     * 取YBOpenApi实例对象
     *
     * 单例，其它的配置参数使用init()或bind()方法设置
     */
    public static function getInstance() {
        if(self::$mpInstance == NULL) {
            self::$mpInstance = new self();
        }

        return self::$mpInstance;
    }

    /**
     * 构造函数
     *
     * 使用 YBOpenApi::getInstance() 初始化
     */
    private function __construct() {

    }

    /**
     * 初始化设置
     *
     * YBOpenApi对象的AppID、AppSecret、回调地址参数设定
     *
     * @param   String 应用的APPID
     * @param   String 应用的AppSecret
     * @param   String 回调地址
     * @return  YBOpenApi 自身实例
     */
    public function init($appID, $appSecret, $callback_url = '') {
        $this->_config['appid'] = $appID;
        $this->_config['seckey'] = $appSecret;
        $this->_config['backurl'] = $callback_url;

        return self::$mpInstance;
    }

    /**
     * 设定访问令牌
     *
     * 如果已经取到访问令牌，使用此方法设定
     * 大多的接口只需要访问令牌即可完成操作
     * 这类接口不需要调用init()方法
     *
     * @param   String 访问令牌
     * @return  YBOpenApi 自身实例
     */
    public function bind($access_token) {
        $this->_config['token'] = $access_token;

        return self::$mpInstance;
    }

    /**
     * HTTP请求辅助函数
     *
     * 对CURL使用简单封装，实现POST与GET请求
     *
     * @param   String api接口地址
     * @param   Array 请求参数数组
     * @param   Boolean 是否使用POST方式请求,默认使用GET方式
     * @return  Array 服务返回的JSON数组
     * @throws YBException
     */
    public static function QueryURL($url, $param = array(), $isPOST = false) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if($isPOST) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $param);
        }else if(!empty($param)) {
            $xi = parse_url($url);
            $url .= empty($xi['query']) ? '?' : '&';
            $url .= http_build_query($param);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        $result = curl_exec($ch);
        if($result == false) {
            throw new YBException(curl_error($ch));
        }
        curl_close($ch);

        return json_decode($result, true);
    }

    /**
     * API调用方法
     *
     * @param   String api接口地址
     * @param   Array 请求参数数组
     * @param   Boolean 是否使用POST方式请求,默认使用GET方式
     * @param   Boolean 请求参数中是否需要传access_token
     * @return  Array 服务返回的JSON数组
     * @throws YBException
     */
    public function request($url, $param = array(), $isPOST = false, $applyToken = true){
        $url = self::YIBAN_OPEN_URL.$url;
        if($applyToken) {
            $param['access_token'] = $this->_config['token'];
        }

        return self::QueryURL($url, $param, $isPOST);
    }

    /**
     * 获取配置参数
     *
     * @param String 配置名称
     * @return mixed
     */
    public function getConfig($configName){
        return $this->_config[$configName];
    }

    /**
     * 授权接口功能类
     *
     * 通用的授权认证接口对象，可以对访问令牌进行查询回收操作
     *
     * @return YBAPI::Authorize
     */
    public function getAuthorize() {
        if (!isset($this->_instance['authorize'])) {
            $this->_instance['authorize'] = new Authorize($this->_config);
        }

        return $this->_instance['authorize'];
    }
    /**
     * 轻应用接入
     *
     * @return YBAPI::IApp
     */
    public function getIApp() {
        if (!isset($this->_instance['iapp'])) {
            $this->_instance['iapp'] = new IApp($this->_config);
        }

        return $this->_instance['iapp'];
    }
}

class IApp {

    const API_OAUTH_CODE = "oauth/authorize";

    private $appJsUrl = 'http://f.yiban.cn/';//

    /**
     * 构造函数
     *
     * 使用YBOpenApi里的config数组初始化
     *
     * @param   Array 配置（对应YBOpenApi里的config数组）
     */
    public function __construct($config) {
        foreach ($config as $key => $val) {
            $this->$key = $val;
        }
    }

    /**
     * 对轻应用授权进行验证
     *
     * 对于轻应用通过页面跳转的方式，
     * 认证时从GET的参数verify_request串中解密出相关授权信息
     * 如已经授权，显示应用内容，
     * 若末授权，则跳转到授权服务去进行授权
     *
     * @return Array 授权信息数据
     */
    public function perform() {

        $code = $_GET['verify_request'];

        if (!isset($code) || empty($code)) {
            throw new YBException(YBLANG::E_EXE_PERFORM);
        }
        $decInfo = $this->decrypts($code);
        if (!$decInfo){
            throw new YBException(YBLANG::E_DEC_STRING);
        }
        if (!is_array($decInfo) || !isset($decInfo['visit_oauth'])) {
            throw new YBException(YBLANG::E_DEC_RESULT);
        }
        if (!$decInfo['visit_oauth']) {//未授权跳转
            header('location: '.$this->forwardurl());
            return false;
        }
        return $decInfo;
    }

    //解密授权信息
    public function decrypts($code){
        $encText = addslashes($code);
        $strText = pack("H*", $encText);
        $decText = (strlen($this->appid) == 16) ? mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $this->seckey, $strText, MCRYPT_MODE_CBC, $this->appid) : mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $this->seckey, $strText, MCRYPT_MODE_CBC, $this->appid);
        if (empty($decText)) {
            return false;
        }
        $decInfo = json_decode(trim($decText), true);
        return $decInfo;
    }


    /**
     * 生成授权认证地址
     *
     * 重定向到授权地址
     * 获取授权认证的CODE用于取得访问令牌
     *
     * @return	String 授权认证页面地址
     */
    private function forwardurl() {
        assert(!empty($this->appid),   YBLANG::E_NO_APPID);
        assert(!empty($this->backurl), YBLANG::E_NO_CALLBACKURL);

        $query = http_build_query(array(
            'client_id'		=> $this->appid,
            'redirect_uri'	=> $this->backurl,
            'display'		=> 'html',
        ));

        return YBOpenApi::YIBAN_OPEN_URL.self::API_OAUTH_CODE.'?'.$query;
    }

}
