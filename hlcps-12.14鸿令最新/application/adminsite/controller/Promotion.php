<?php
namespace app\adminsite\controller;

use data\service\Address;
use data\service\Config as ConfigService; 
use data\service\promotion\PromoteRewardRule;
use data\service\Promotion as PromotionService;
use data\service\Member;
use data\service\Goods as GoodsService;
use data\service\GroupBuy;
use data\service\GoodsCategory as GoodsCategory;
use data\model\NsCouponTypeModel as NsCouponTypeModel;

/**
 * 营销控制器
 *
 * @author Administrator
 *        
 */
class Promotion extends BaseController
{

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 优惠券类型列表
     *
     * @return multitype:number unknown |Ambigous <\think\response\View, \think\response\$this, \think\response\View>
     */
    public function couponTypeList()
    {
        if (request()->isAjax()) {
            $page_index = request()->post("page_index", 1);
            $page_size = request()->post("page_size", PAGESIZE);
            $search_text = request()->post('search_text', '');
            $coupon = new PromotionService();
            $condition = array(
                'shop_id' => $this->instance_id,
                'coupon_name' => array(
                    'like',
                    '%' . $search_text . '%'
                )
            );
            $list = $coupon->getCouponTypeList($page_index, $page_size, $condition, 'start_time desc');
            return $list;
        } else {
            return view($this->style . "Promotion/couponTypeList");
        }
    }
    
    /**
     * 优惠券发放记录
     */
    public function couponGrantLog()
    {	
    	if (request()->isAjax()) {
            $page_index = request()->post("page_index", 1);
            $page_size = request()->post('page_size', PAGESIZE);
            $search_text = request()->post('search_text', '');
            $status = request()->post('status', -1);
            $coupon_type_id = request()->post('coupon_type_id', '');
            $coupon = new PromotionService();
            
            $condition = array(
                'coupon_type_id' => $coupon_type_id
            );
            if ($status !== '-1') {
                $condition['state'] = $status;
                $list = $coupon->getCouponGrantLogList($page_index, $page_size, $condition);
            } else {
                $list = $coupon->getCouponGrantLogList($page_index, $page_size, $condition);
            }
            
            return $list;
        }
        $coupon_type_id = request()->get('coupon_type_id', 0);
    	$status = request()->get('status', -1);
    	$this->assign('coupon_type_id', $coupon_type_id);
    	return view($this->style."Promotion/couponGrantLog");
    }

    /**
     * 删除优惠券类型
     */
    public function delcoupontype()
    {
        $coupon_type_id = request()->post('coupon_type_id', '');
        if (empty($coupon_type_id)) {
            $this->error("没有获取到优惠券信息");
        }
        $coupon = new PromotionService();
        $res = $coupon->deletecouponType($coupon_type_id);
        return AjaxReturn($res);
    }

    /**
     * 添加优惠券类型
     */
    public function addCouponType()
    {
        if (request()->isAjax()) {
            $coupon_name = request()->post('coupon_name', '');
            $money = request()->post('money', '');
            $count = request()->post('count', '');
            $max_fetch = request()->post('max_fetch', '');
            $at_least = request()->post('at_least', '');
            $need_user_level = request()->post('need_user_level', '');
            $range_type = request()->post('range_type', '');
            $start_time = request()->post('start_time', '');
            $end_time = request()->post('end_time', '');
            $is_show = request()->post('is_show', '');
            $is_bespoke = request()->post('is_bespoke', '');
            $goods_list = request()->post('goods_list', '');
            $coupon = new PromotionService();
            $retval = $coupon->addCouponType($coupon_name, $money, $count, $max_fetch, $at_least, $need_user_level, $range_type, $start_time, $end_time, $is_show, $is_bespoke, $goods_list);
            return AjaxReturn($retval);
        } else {
            return view($this->style . "Promotion/addCouponType");
        }
    }
     /**
     * 是否首页显示&是否为预约送优惠券
     */
    public function modifyState()
    {
        $couponType = new NsCouponTypeModel();
        $coupon_type_id = request()->get('id',0);
        $name = request()->get('name','');
        $state = request()->get('state',0);
        $state==0?$state=1:$state=0;
        $result = $couponType->ModifyTableField('coupon_type_id',$coupon_type_id,$name,$state);
        if($result){
            $res['code'] = 1;
            $res['message'] = '状态修改成功';
        }else{
            $res['code'] = -1;
            $res['message'] = '状态修改失败';
        }
        return $res;
    }
    public function updateCouponType()
    {
        $coupon = new PromotionService();
        if (request()->isAjax()) {
            $coupon_type_id = request()->post('coupon_type_id', '');
            $coupon_name = request()->post('coupon_name', '');
            $money = request()->post('money', '');
            $count = request()->post('count', '');
            $repair_count = request()->post('repair_count', '');
            $max_fetch = request()->post('max_fetch', '');
            $at_least = request()->post('at_least', '');
            $need_user_level = request()->post('need_user_level', '');
            $range_type = request()->post('range_type', '');
            $start_time = request()->post('start_time', '');
            $end_time = request()->post('end_time', '');
            $is_show = request()->post('is_show', '');
            $is_bespoke = request()->post('is_bespoke', '');
            $goods_list = request()->post('goods_list', '');
            $retval = $coupon->updateCouponType($coupon_type_id, $coupon_name, $money, $count, $repair_count, $max_fetch, $at_least, $need_user_level, $range_type, $start_time, $end_time, $is_show, $is_bespoke, $goods_list);
            return AjaxReturn($retval);
        } else {
           
            $coupon_type_id = request()->get('coupon_type_id', 0);
            if ($coupon_type_id == 0) {
                $this->error("没有获取到类型");
            }
            $coupon_type_data = $coupon->getCouponTypeDetail($coupon_type_id);
           
            $goods_id_array = array();
            foreach ($coupon_type_data['goods_list'] as $k => $v) {
                $goods_id_array[] = $v['goods_id'];
            }
            $goods_id_array = join(',',$goods_id_array);
            
            $coupon_type_data['goods_id_array'] = $goods_id_array;
            $this->assign("coupon_type_info", $coupon_type_data);
            
            return view($this->style . "Promotion/updateCouponType");
        }
    }

    /**
     * 获取优惠券详情
     */
    public function getCouponTypeInfo()
    {
        $coupon = new PromotionService();
        $coupon_type_id = request()->post('coupon_type_id', '');
        $coupon_type_data = $coupon->getCouponTypeDetail($coupon_type_id);
        return $coupon_type_data;
    }

    /**
     * 功能：积分管理
     */
    public function pointConfig()
    {
        $pointConfig = new PromotionService();
        if (request()->isAjax()) {
            $convert_rate = request()->post('convert_rate', '');
            $is_open = request()->post('is_open', 0);
            $desc = request()->post('desc', 0);
            $retval = $pointConfig->setPointConfig($convert_rate, $is_open, $desc);
            return AjaxReturn($retval);
        }
        $pointconfiginfo = $pointConfig->getPointConfig();
        $this->assign("pointconfiginfo", $pointconfiginfo);
        return view($this->style . "Promotion/pointConfig");
    }

    /**
     * 满额包邮
     */
    public function fullShipping()
    {
        $full = new PromotionService();
        if (request()->isAjax()) {
            $is_open = request()->post('is_open', '');
            $full_mail_money = request()->post('full_mail_money', '');
            $no_mail_province_id_array = request()->post('no_mail_province_id_array', '');
            $no_mail_city_id_array = request()->post("no_mail_city_id_array", '');
            $res = $full->updatePromotionFullMail(0, $is_open, $full_mail_money, $no_mail_province_id_array, $no_mail_city_id_array);
            return AjaxReturn($res);
        } else {
            $info = $full->getPromotionFullMail($this->instance_id);
            $this->assign("info", $info);
            $existing_address_list['province_id_array'] = explode(',', $info['no_mail_province_id_array']);
            $existing_address_list['city_id_array'] = explode(',', $info['no_mail_city_id_array']);
            $address = new Address();
            // 目前只支持省市，不支持区县，在页面上不会体现 
            $address_list = $address->getAreaTree($existing_address_list);
            $this->assign("address_list", $address_list);
            $no_mail_province_id_array = array();
            if (count($existing_address_list['province_id_array']) > 0) {
                foreach ($existing_address_list['province_id_array'] as $v) {
					if(!empty($v)){
						 $no_mail_province_id_array[] = $address->getProvinceName($v);
					}
                }
            }
			$no_mail_province = "";
			if(count($no_mail_province_id_array) > 0){
				$no_mail_province = implode(',', $no_mail_province_id_array);
			}
            $this->assign("no_mail_province", $no_mail_province);
            return view($this->style . "Promotion/fullShipping");
        }
    }

    /**
     * 积分奖励
     */
    public function integral()
    {
        $rewardRule = new PromoteRewardRule();
        if (request()->isAjax()) {
            $shop_id = $this->instance_id;
            $sign_point = request()->post('sign_point', 0);
            $share_point = request()->post('share_point', 0);
            $reg_member_self_point = request()->post('reg_member_self_point', 0);
            $reg_member_one_point = 0;
            $reg_member_two_point = 0;
            $reg_member_three_point = 0;
            $reg_promoter_self_point = 0;
            $reg_promoter_one_point = 0;
            $reg_promoter_two_point = 0;
            $reg_promoter_three_point = 0;
            $reg_partner_self_point = 0;
            $reg_partner_one_point = 0;
            $reg_partner_two_point = 0;
            $reg_partner_three_point = 0;
            $click_point = request()->post("click_point", 0);
            $comment_point = request()->post("comment_point", 0);
            
            $reg_coupon = request()->post("reg_coupon", 0);
            $click_coupon = request()->post("click_coupon", 0);
            $comment_coupon = request()->post("comment_coupon", 0);
            $sign_coupon = request()->post("sign_coupon", 0);
            $share_coupon = request()->post("share_coupon", 0);
            
            $res = $rewardRule->setPointRewardRule($shop_id, $sign_point, $share_point, $reg_member_self_point, $reg_member_one_point, $reg_member_two_point, $reg_member_three_point, $reg_promoter_self_point, $reg_promoter_one_point, $reg_promoter_two_point, $reg_promoter_three_point, $reg_partner_self_point, $reg_partner_one_point, $reg_partner_two_point, $reg_partner_three_point, $click_point, $comment_point, $reg_coupon, $click_coupon, $comment_coupon, $sign_coupon, $share_coupon);
            return AjaxReturn($res);
        }
        $res = $rewardRule->getRewardRuleDetail($this->instance_id);
        $Config = new ConfigService();
        $integralConfig = $Config->getIntegralConfig($this->instance_id);
        $coupon = new PromotionService();
        $condition = array(
            'shop_id' => $this->instance_id,
            'start_time' => array(
                'ELT',
                time()
            ),
            'end_time' => array(
                'EGT',
                time()
            )
        );
        $couponlist = $coupon->getCouponTypeList($page_index, $page_size, $condition, 'start_time desc');
        $this->assign("res", $res);
        $this->assign("integralConfig", $integralConfig);
        $this->assign("couponlist", $couponlist['data']);
        return view($this->style . "Promotion/integral");
    }

    /**
     *
     * @return Ambigous <multitype:unknown, multitype:unknown unknown string >
     */
    public function setIntegralAjax()
    {
        $register = request()->post('register', 0);
        $sign = request()->post('sign', 0);
        $share = request()->post('share', 0);
        $reg_coupon = request()->post('reg_coupon', 0);
        $click_coupon = request()->post('click_coupon', 0);
        $comment_coupon = request()->post('comment_coupon', 0);
        $sign_coupon = request()->post('sign_coupon', 0);
        $share_coupon = request()->post('share_coupon', 0);
        $Config = new ConfigService();
        $retval = $Config->SetIntegralConfig($this->instance_id, $register, $sign, $share, $reg_coupon, $click_coupon, $comment_coupon, $sign_coupon, $share_coupon);
        return AjaxReturn($retval);
    }









 




    /**
     * 赠送优惠券类型列表
     *
     */
    public function sendCouponTypeList()
    {
        if (request()->isAjax()) {
            $now_time = time();
            $coupon = new PromotionService();
            $condition = array(
                'shop_id' => $this->instance_id,
                'end_time' => array(
                    'gt',
                    $now_time
                )
            );
            $list = $coupon->getCouponTypeList(1, 0, $condition, 'create_time desc');
            return $list;
        } else {
            return view($this->style . "Promotion/couponTypeList");
        }
    }
    


    
    /*
     * 课程选择弹框控制器 
     */
    public function goodsSelectList(){
        
        if(request()->post()){
            $page_index = request()->post("page_index", 1);
            $page_size = request()->post("page_size", PAGESIZE);
            $goods_name = request()->post("goods_name", ""); 
       
            $category_id_1 = request()->post('category_id_1', '');
            $category_id_2 = request()->post('category_id_2', '');
            $category_id_3 = request()->post('category_id_3', '');
            $selectGoodsLabelId = request()->post('selectGoodsLabelId', '');
            $supplier_id = request()->post('supplier_id', '');
            $goods_type = request()->post("goods_type", ""); // 课程类型
            $goods_code = request()->post('code', '');
            $data = request()->post("data");
            if(!empty($goods_type)){
                $goods_type = $this->getValueByKey($data, 'goods_type');
            }
           
            $state = $this->getValueByKey($data, 'state');
            $is_have_sku = $this->getValueByKey($data, 'is_have_sku');
            $stock = $this->getValueByKey($data, 'stock');
            
            //课程名称
            $condition = array(
                "goods_name" => [
                    "like",
                    "%$goods_name%"
                ],
           
            );
            
            //课程类型
            $condition['goods_type'] = ['in',$goods_type];
            
            //课程编码
            if (! empty($goods_code)) {
                $condition["code"] = array(
                    "like",
                    "%" . $goods_code . "%"
                );
            }
            
            //供货商
            if ($supplier_id != '') {
                $condition['supplier_id'] = $supplier_id;
            }
            
            
            
            //课程状态
            $condition['state'] = ['in',$state];
            
            //是否有sku
            if($is_have_sku == 0){
                $condition["goods_spec_format"] = '[]';
            }
            
            //是否有库存
            if($stock == 1){
                $condition['stock'] = ['GT',0];
            }
            
            //课程分类
            if ($category_id_3 != "") {
                $condition["category_id_3"] = $category_id_3;
            } elseif ($category_id_2 != "") {
                $condition["category_id_2"] = $category_id_2;
            } elseif ($category_id_1 != "") {
                $condition["category_id_1"] = $category_id_1;
            }
            $goods_detail = new GoodsService();
            $result = $goods_detail->getSearchGoodsList($page_index, $page_size, $condition);
            return $result;
        }else{
            $type = request()->get('type');
            $this->assign('type',$type);
            
            $data = request()->get('data');
            $data = rtrim($data,',');
            $this->assign('data',$data);
            $goods_type = $this->getValueByKey($data, 'goods_type');
            $state = $this->getValueByKey($data, 'state');
            $is_have_sku = $this->getValueByKey($data, 'is_have_sku');
            $stock = $this->getValueByKey($data, 'stock');
            
            // 查找一级课程分类
            $goodsCategory = new GoodsCategory();
            $oneGoodsCategory = $goodsCategory->getGoodsCategoryListByParentId(0);
            $this->assign("oneGoodsCategory", $oneGoodsCategory);
            return view($this->style . "Promotion/goodsSelectList");
        }
        
    }
    
    //获取传值数组的值
    public function getValueByKey($str,$key){
        
        $arr = explode(',',$str);
        
        foreach($arr as $k=>$v){
            $v_arr = explode(':',$v);
            if($key == $v_arr[0]){
                return $v_arr[1];
            }
        }
        
        return 0;
    }
    
}