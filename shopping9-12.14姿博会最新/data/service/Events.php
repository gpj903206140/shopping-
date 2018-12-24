<?php
namespace data\service;
use data\api\IEvents;
use data\model\NsPromotionMansongModel;
use data\service\Order;
use data\model\NsOrderModel;
use data\model\NsPromotionMansongGoodsModel;
use data\model\NsPromotionDiscountModel;
use data\model\NsPromotionDiscountGoodsModel;
use data\model\NsGoodsSkuModel;
use data\model\NsGoodsModel;
use data\model\NsCouponModel;
use data\model\NsPromotionGamesModel;
use think\Log;
use data\model\NsTuangouGroupModel;
use data\model\NsPromotionGroupBuyModel;
use data\model\NsOrderPresellModel;
use data\model\BaseModel;
use data\model\NsPromotionTopicModel;
/**
 * 计划任务
 */
class Events implements IEvents{
    /**
     * (non-PHPdoc)
     * @see \data\api\IEvents::giftClose()
     */
    public function giftClose(){
        
    }
    /**
     * (non-PHPdoc)
     * @see \data\api\IEvents::mansongClose()
     */
    public function mansongOperation(){
        $mansong = new NsPromotionMansongModel();
        $mansong->startTrans();
        try{
            $time = time();
            $condition_close = array(
                'end_time' => array('LT', $time),
                'status'   => array('NEQ', 3)
            );
            $condition_start = array(
                'start_time' => array('ELT', $time),
                'status'   => 0
            );
            $mansong->save(['status' => 4], $condition_close);
            $mansong->save(['status' => 1], $condition_start);
            $mansong_goods = new NsPromotionMansongGoodsModel();
            $mansong_goods->save(['status' => 4], $condition_close);
            $mansong_goods->save(['status' => 1], $condition_start);
            $mansong->commit();
            return 1;
        }catch (\Exception $e)
        {
            $mansong->rollback();
            return $e->getMessage();
        }
       
    }
    /**
     * (non-PHPdoc)
     * @see \data\api\IEvents::ordersClose()
     */
    public function ordersClose(){
        $order_model = new NsOrderModel();
       
        try{
            $config = new Config();
            $config_info = $config->getConfig(0, 'ORDER_BUY_CLOSE_TIME');
            if(!empty($config_info['value'])&$config_info['value'] != 0)
            {
                $close_time = $config_info['value'];
            }else{
              return 1;
            }
            $time = time()-$close_time*60;//订单自动关闭
            $condition = array(
                'order_status' => array('in','0,6'),
                'create_time'  => array('LT', $time),
                'payment_type' => array('neq', 6)
            );
            $order_list = $order_model->getQuery($condition, 'order_id', '');
            if(!empty($order_list))
            {
                $order = new Order();
                foreach ($order_list as $k => $v)
                {
                    if(!empty($v['order_id']))
                    {
                        $order->orderClose($v['order_id']);
                    }
                   
                }
                    
            }
            return 1;
        }catch (\Exception $e)
        {
            return $e->getMessage();
        }
        
    }
    /**
     * (non-PHPdoc)
     * @see \data\api\IEvents::ordersComplete()
     */
    public function ordersComplete(){
        $order_model = new NsOrderModel();
        try{
            
            $config = new Config();
            $config_info = $config->getConfig(0, 'ORDER_DELIVERY_COMPLETE_TIME');
            if($config_info['value'] != '')
            {
                $complete_time = $config_info['value'];
            }else{
                $complete_time = 7;//7天
            }
            $time = time()-3600*24*$complete_time;//订单自动完成

            $condition = array(
                'order_status' => 3,
                'sign_time'  => array('LT', $time)
            );
            $order_list = $order_model->getQuery($condition, 'order_id', '');
            if(!empty($order_list))
            {
                $order = new Order();
                foreach ($order_list as $k => $v)
                {
                    if(!empty($v['order_id']))
                    {
                        $order->orderComplete($v['order_id']);
                    }
                    
                }
        
            }
     
            return 1;
        }catch (\Exception $e)
        {
            return $e->getMessage();
        }
    }
    /**
     * (non-PHPdoc)
     * @see \data\api\IEvents::discountOperation()
     */
    public function discountOperation(){
        $discount = new NsPromotionDiscountModel();
        $discount->startTrans();
        try{
            $time = time();
            $discount_goods = new NsPromotionDiscountGoodsModel();
            /************************************************************结束活动**************************************************************/
            $condition_close = array(
                'end_time' => array('LT', $time),
                'status'   => array('NEQ', 3)
            );
             $discount->save(['status' => 4], $condition_close);
             $discount_close_goods_list = $discount_goods->getQuery($condition_close, '*', '');
             if(!empty($discount_close_goods_list))
             {
                 foreach ( $discount_close_goods_list as $k => $discount_goods_item)
                 {
                     $goods = new NsGoodsModel();
             
                     $data_goods = array(
                         'promotion_type' => 2,
                         'promote_id'     => $discount_goods_item['discount_id']
                     );
                     $goods_id_list = $goods->getQuery($data_goods, 'goods_id', '');
                     if(!empty($goods_id_list))
                     {
                         foreach($goods_id_list as $k => $goods_id)
                         {
                             $goods_info = $goods->getInfo(['goods_id' => $goods_id['goods_id']], 'promotion_type,price');
                             $goods->save(['promotion_price' => $goods_info['price']], ['goods_id'=> $goods_id['goods_id'] ]);
                             $goods_sku = new NsGoodsSkuModel();
                             $goods_sku_list = $goods_sku->getQuery(['goods_id'=> $goods_id['goods_id'] ], 'price,sku_id', '');
                             foreach ($goods_sku_list as $k_sku => $sku)
                             {
                                 $goods_sku = new NsGoodsSkuModel();
                                 $data_goods_sku = array(
                                     'promote_price' => $sku['price']
                                 );
                                 $goods_sku->save($data_goods_sku, ['sku_id' => $sku['sku_id']]);
                             }
                             
                         }
                        
                     }
                     $goods->save(['promotion_type' => 0, 'promote_id' => 0], $data_goods);
                    
                 }
             }
             $discount_goods->save(['status' => 4], $condition_close);
             /************************************************************结束活动**************************************************************/
             /************************************************************开始活动**************************************************************/
            $condition_start = array(
                'start_time' => array('ELT', $time),
                'status'   => 0
            );
            //查询待开始活动列表
            $discount_goods_list = $discount_goods->getQuery($condition_start, '*', '');
            if(!empty($discount_goods_list))
            {
                foreach ( $discount_goods_list as $k => $discount_goods_item)
                {
                    $goods = new NsGoodsModel();
                    $goods_info = $goods->getInfo(['goods_id' => $discount_goods_item['goods_id']],'promotion_type,price');
                    $data_goods = array(
                        'promotion_type' => 2,
                        'promote_id'     => $discount_goods_item['discount_id'],
                        'promotion_price'  => $goods_info['price'] *$discount_goods_item['discount']/10 
                    );
                    $goods->save($data_goods,['goods_id' => $discount_goods_item['goods_id']]);
                    $goods_sku = new NsGoodsSkuModel();
                    $goods_sku_list = $goods_sku->getQuery(['goods_id'=> $discount_goods_item['goods_id'] ], 'price,sku_id', '');
                    foreach ($goods_sku_list as $k_sku => $sku)
                    {
                        $goods_sku = new NsGoodsSkuModel();
                        $data_goods_sku = array(
                            'promote_price' => $sku['price']*$discount_goods_item['discount']/10
                        );
                        $goods_sku->save($data_goods_sku, ['sku_id' => $sku['sku_id']]);
                    }
                }
            }
            $discount_goods->save(['status' => 1], $condition_start);
            $discount->save(['status' => 1], $condition_start);
            /************************************************************开始活动**************************************************************/
            $discount->commit();
            return 1;
        }catch (\Exception $e)
        {
            $discount->rollback();
            return $e;
        }
    }
    /**
     * (non-PHPdoc)
     * @see \data\api\IEvents::autoDeilvery()
     */
    public function autoDeilvery(){
        $order_model = new NsOrderModel();

        try{
        
            $config = new Config();
            $config_info = $config->getConfig(0, 'ORDER_AUTO_DELIVERY');
            if(!empty($config_info['value'])&$config_info['value'] != 0)
            {
                $delivery_time = $config_info['value'];
            }else{
                return 1;
            }
            $time = time()-3600*24*$delivery_time;//订单自动完成
        
            $condition = array(
                'order_status' => 2,
                'consign_time'  => array('LT', $time)
            );
            $order_list = $order_model->getQuery($condition, 'order_id', '');
             if(!empty($order_list))
            {
                $order = new \data\service\Order\Order();
                foreach ($order_list as $k => $v)
                {
                    if(!empty($v['order_id']))
                    {
                        $order->orderAutoDelivery($v['order_id']);
                    }
                    
                }
        
            } 

            return 1;
        }catch (\Exception $e)
        {
            return $e->getMessage();
        }
    }
    
    /**
     * 优惠券自动过期
     * {@inheritDoc}
     * @see \data\api\IEvents::autoCoupon()
     */
    public function autoCouponClose(){
        $ns_coupon_model = new NsCouponModel();
        $ns_coupon_model->startTrans();
        try{
            $condition['end_time'] = array('LT',time());
            $condition['state'] = array('NEQ',2);//排成已使用的优惠券
            $count = $ns_coupon_model->getCount($condition);
            $res = -1;
            if($count){
                $res = $ns_coupon_model->save(['state'=>3],$condition);
            }
            $ns_coupon_model->commit();
            return $res;
        }catch (\Exception $e)
        {
            $ns_coupon_model->rollback();
            return $e->getMessage();
        }
    }
    
    /**
     * 营销游戏自动执行操作，改变活动状态
     */
    public function autoPromotionGamesOperation(){
        $model = new NsPromotionGamesModel();
        $model->startTrans();
        try{
            $time = time();
            
            //活动开始条件：当前时间大于开始时间，并且活动状态等于0（未开始）
            $condition_start = array(
                'start_time' => array('ELT', $time),
                'status'   => 0
            );
            
            //活动结束条件：当前时间大于结束时间，并且活动状态不等于-1（已结束）
            $condition_close = array(
                'end_time' => array('LT', $time),
                'status'   => array('NEQ', -1)
            );
            
            $start_count = $model->getCount($condition_start);
            $close_count = $model->getCount($condition_close);
            
            if($start_count){
                $model->save(['status'=>1],$condition_start);
            }
            
            if($close_count){
                $model->save(['status'=>-1],$condition_close);
            }
            
            $model->commit();
        }catch(\Exception $e){
            $model->rollback();
            return $e->getMessage();
        }
    }
    /*
     * (non-PHPdoc)
     * @see \data\api\IEvents::pintuanGroupClose()
     */
    public function pintuanGroupClose()
    {
        // TODO Auto-generated method stub
        // 拼团过期时关闭拼团订单
        $pintuan_group = new NsTuangouGroupModel();
        $pintuan_group->startTrans();
        try {
            $condition['end_time'] = array(
                'LT',
                time()
            );
            $condition['status'] = array(
                'EQ',
                1
            ); // 排成已使用的优惠券
            $count = $pintuan_group->getCount($condition);
            $res = - 1;
            if ($count) {
                $res = $pintuan_group->save([
                    'status' => - 1
                ], $condition);
            }
            $pintuan_group->commit();
            return $res;
        } catch (\Exception $e) {
            $pintuan_group->rollback();
            return $e->getMessage();
        }
    }
    
    /*
     * 团购活动自动过期
     */
   public function  autoGroupBuyClose()
   {
       $promotion_group_buy = new NsPromotionGroupBuyModel();
       $promotion_group_buy->startTrans();
       try{
           $condition['end_time'] = array('LT',time());
           $condition['status'] = array('NEQ',0);//排成已使用的 团购
           $count = $promotion_group_buy->getCount($condition);
           $res = -1;
           if($count){
               $res = $promotion_group_buy->save(['status'=>-1],$condition);
           }
           $promotion_group_buy->commit();
           return $res;
       }catch (\Exception $e)
       {
           $promotion_group_buy->rollback();
           return $e->getMessage();
       }
   }
   
   /**
    * 预售订单预售结束
    */
   public function autoPresellOrder(){
       
       $presell_order_model = new NsOrderPresellModel();
       $presell_order_model->startTrans();
       
       try {
        
           $condition = array(
               'order_status' => 1,
               'presell_delivery_time' => array('elt', time())
//                'presell_delivery_time' => array('elt', 1522293275)
           );
           $presell_order_list = $presell_order_model->getQuery($condition, 'relate_id, payment_type, is_full_payment', '');
          
           $presell_order_model->save(['order_status'=> 2], $condition);
           
           foreach($presell_order_list as $item){
               $order_model = new NsOrderModel();
               $order_condition = array(
                   'order_id' => $item['relate_id'],
                   'order_status' => 7
               );
               $order_model->save(['order_status'=>0], $order_condition);
               
               if($item['is_full_payment'] == 1){
                   $order_service = new Order();
                   $order_service->orderOffLinePay($item['relate_id'], $item['payment_type'], 0); // 默认微信支付
               }
           }
           $presell_order_model->commit();
       } catch (\Exception $e) {
           
            $presell_order_model->rollback();
           return $e->getMessage();
       }
       
   }
   
   /**
    * 专题活动自动状态		
    */
   public function autoTopicClose()
   {
	   	$model = new NsPromotionTopicModel();
	   	$model->startTrans();
	   	try{
	   		$time = time();
	   	
	   		//活动开始条件：当前时间大于开始时间，并且活动状态等于0（未开始）
	   		$condition_start = array(
	   				'start_time' => array('ELT', $time),
	   				'status'   => 0
	   		);
	   	
	   		//活动结束条件：当前时间大于结束时间，并且活动状态不等于4（已结束）
	   		$condition_close = array(
	   				'end_time' => array('LT', $time),
	   				'status'   => array('NEQ', 4)
	   		);
	   	
	   		$start_count = $model->getCount($condition_start);
	   		$close_count = $model->getCount($condition_close);
	   	
	   		if($start_count){
	   			$model->save(['status'=>1],$condition_start);
	   		}
	   	
	   		if($close_count){
	   			$model->save(['status'=>4],$condition_close);
	   		}
	   	
	   		$model->commit();
	   	}catch(\Exception $e){
	   		$model->rollback();
	   		return $e->getMessage();
	   	}
   }
}
