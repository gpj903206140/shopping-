<?php
namespace data\service;

/**
 * 课程服务层
 */
use data\api\IGoods as IGoods;
use data\model\AlbumPictureModel as AlbumPictureModel;
use data\model\NsAttributeModel;
use data\model\NsAttributeValueModel;
use data\model\NsCartModel;
use data\model\NsClickFabulousModel;
use data\model\NsConsultModel;
use data\model\NsConsultTypeModel;
use data\model\NsCouponGoodsModel;
use data\model\NsCouponModel;
use data\model\NsCouponTypeModel;
use data\model\NsGoodsCategoryModel as NsGoodsCategoryModel;
use data\model\NsGoodsDeletedModel;
use data\model\NsGoodsDeletedViewModel;
use data\model\NsGoodsEvaluateModel;
use data\model\NsGoodsModel as NsGoodsModel;
use data\model\NsGoodsSkuDeletedModel;
use data\model\NsGoodsSkuModel as NsGoodsSkuModel;
use data\model\NsGoodsSkuPictureDeleteModel;
use data\model\NsGoodsSkuPictureModel;
use data\model\NsGoodsViewModel as NsGoodsViewModel;
use data\model\NsOrderGoodsModel;
use data\model\NsOrderModel;
use data\model\NsPromotionDiscountModel;
use data\model\NsShopModel;
use data\service\BaseService as BaseService;
use data\service\promotion\GoodsExpress;
use data\service\promotion\GoodsPreference;
use data\service\promotion\PromoteRewardRule;
use data\service\GroupBuy as GroupBuyService;
use think;
use think\Cache;
use data\model\NsGoodsLadderPreferentialModel;
use data\model\NsGoodsBrowseModel;
use think\Log;

use data\model\NcCmsArticleCommentModel as cmsArticleComment;
use data\model\NcCmsArticleModel as cmsArticle;

class Goods extends BaseService implements IGoods
{

    private $goods;

    function __construct()
    {
        parent::__construct();
        $this->goods = new NsGoodsModel();
    }
    /**
     * 添加评论(non-PHPdoc)
     *
     * @see \data\api\IGoods::addCart()
     */
    public function addComment($uid, $nick_name, $article_id, $content,$type,$datatype,$is_show)
    {
        $retval = array(
            'code' => 0,
            "message" => ""
        );
        // 课程限购，判断是否允许添加到购物车
        if($type==0){
            $message = "评论成功";
        }elseif($type==1){
            $message = "收藏成功";
        }
        $retval = array(
            "code" => 1,
            "message" => $message
        );
        if ($uid > 0) {
            $comment = new cmsArticleComment();
            $cmsArticle = new cmsArticle();
            $comment->startTrans();
            $data = array(
                'uid' => $uid,
                'article_id' => $article_id,
                'nick_name' => $nick_name,
                'content'=>$content,
                'type'=>$type,
                'is_show'=>$is_show,
                'create_time'=>time(),
            );
            
            if($type==0){
                $result1 = $comment->save($data);
                $reslut2 = $cmsArticle->where('article_id',$article_id)->setInc('comment_count');
                if($result1&&$reslut2){
                    $comment->commit();
                    $retval['code'] = $comment->comment_id;
                }else{
                    $comment->rollback();
                    $retval['code'] = -1;
                }
            }elseif($type==1){
                if($datatype==0){
                    $result1 = $comment->save($data);
                    if($result1){
                        $comment->commit();
                        $retval['code'] = $comment->comment_id;
                    }else{
                        $comment->rollback();
                        $retval['code'] = -1;
                    }
                }elseif($datatype==1){
                    $result1 = $comment->destroy(['article_id'=>$article_id,'uid'=>$uid,'type'=>1]);
                    if($result1){
                        $comment->commit();
                        $retval['message'] = "取消收藏成功";
                        $retval['code'] = 1;
                    }else{
                        $comment->rollback();
                        $retval['code'] = -1;
                    }
                }
                
            }
            
            
        }
        return $retval;
    }
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::getGoodsList()
     */
    public function getGoodsList($page_index = 1, $page_size = 0, $condition = '', $order = 'ng.sort asc,ng.create_time desc', $group_id = 0)
    {
        $goods_view = new NsGoodsViewModel();
        // 针对课程分类
        if (! empty($condition['ng.category_id'])) {
            $goods_category = new GoodsCategory();
            $category_list = $goods_category->getCategoryTreeList($condition['ng.category_id']);
            unset($condition['ng.category_id']);
            $query_goods_ids = "";
            $goods_list = $goods_view->getGoodsViewQueryField($condition, "ng.goods_id");
            if (! empty($goods_list) && count($goods_list) > 0) {
                foreach ($goods_list as $goods_obj) {
                    if ($query_goods_ids === "") {
                        $query_goods_ids = $goods_obj["goods_id"];
                    } else {
                        $query_goods_ids = $query_goods_ids . "," . $goods_obj["goods_id"];
                    }
                }
                $extend_query = "";
                $category_str = explode(",", $category_list);
                foreach ($category_str as $category_id) {
                    if ($extend_query === "") {
                        $extend_query = " FIND_IN_SET( " . $category_id . ",ng.extend_category_id) ";
                    } else {
                        $extend_query = $extend_query . " or FIND_IN_SET( " . $category_id . ",ng.extend_category_id) ";
                    }
                }
                $condition = " ng.goods_id in (" . $query_goods_ids . ") and ( ng.category_id in (" . $category_list . ") or " . $extend_query . ")";
            }
        }
        $goods_view = new NsGoodsViewModel();
        $list = $goods_view->getGoodsViewList($page_index, $page_size, $condition, $order);
        if (! empty($list['data'])) {
            // 用户针对课程的收藏
            foreach ($list['data'] as $k => $v) {
                if (! empty($this->uid)) {
                    $member = new Member();
                    $list['data'][$k]['is_favorite'] = $member->getIsMemberFavorites($this->uid, $v['goods_id'], 'goods');
                } else {
                    $list['data'][$k]['is_favorite'] = 0;
                }
                // 查询课程单品活动信息
                $goods_preference = new GoodsPreference();
                $goods_promotion_info = $goods_preference->getGoodsPromote($v['goods_id']);
                $list["data"][$k]['promotion_info'] = $goods_promotion_info;
                
                if($v['point_exchange_type'] == 0 || $v['point_exchange_type'] == 2){
                    $list['data'][$k]['display_price'] = '￥'.$v["promotion_price"];
                }else{
                    if($v['point_exchange_type'] == 1 && $v["promotion_price"] > 0){
                        $list['data'][$k]['display_price'] = '￥'.$v["promotion_price"].'+'.$v["point_exchange"].'积分';
                    }else{
                        $list['data'][$k]['display_price'] = $v["point_exchange"].'积分';
                    }
                }
                
            }
        }
        return $list;
        
        // TODO Auto-generated method stub
    }

    /**
     * 直接查询课程列表
     *
     * @param number $page_index            
     * @param number $page_size            
     * @param string $condition            
     * @param string $order            
     */
    public function getGoodsViewList($page_index = 1, $page_size = 0, $condition = '', $order = 'ng.sort asc')
    {
        $goods_view = new NsGoodsViewModel();
        $list = $goods_view->getGoodsViewList($page_index, $page_size, $condition, $order);
        return $list;
    }

    /**
     * 排行数据查询
     *
     * @param number $page_index            
     * @param number $page_size            
     * @param string $condition            
     * @param string $order            
     * @return multitype:\data\model\unknown
     */
    public function getGoodsRankViewList($page_index = 1, $page_size = 0, $condition = '', $order = 'ng.sort asc')
    {
        $goods_model = new NsGoodsModel();
        // 针对课程分类
        $viewObj = $goods_model->alias("ng")
            ->join('sys_album_picture ng_sap', 'ng_sap.pic_id = ng.picture', 'left')
            ->field("ng.goods_id,ng.goods_name,ng_sap.pic_cover_mid,ng.promotion_price,ng.market_price,ng.goods_type,ng.stock,ng_sap.pic_id,ng.max_buy,ng.state,ng.is_hot,ng.is_recommend,ng.is_new,ng.sales,ng_sap.pic_cover_small");
        $queryList = $goods_model->viewPageQuery($viewObj, $page_index, $page_size, $condition, $order);
        $queryCount = $this->getGoodsQueryCount($condition);
        $list = $goods_model->setReturnList($queryList, $queryCount, $page_size);
        return $list;
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::getGoodsCount()
     */
    public function getGoodsCount($condition)
    {
        $count = $this->goods->where($condition)->count();
        return $count;
        
        // TODO Auto-generated method stub
    }

    /**
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::goods_QRcode_make()
     */
    function goods_QRcode_make($goodsId, $url)
    {
        $data = array(
            'QRcode' => $url
        );
        $result = $this->goods->save($data, [
            'goods_id' => $goodsId
        ]);
        if ($result > 0) {
            return SUCCESS;
        } else {
            return UPDATA_FAIL;
        }
    }

    /**
     * 添加修改课程
     * goods_id '课程id(SKU)',
     * goods_name '课程名称',
     * shop_id '店铺id',
     * category_id '课程分类id',
     * category_id_1 '一级分类id',
     * category_id_2 '二级分类id',
     * category_id_3 '三级分类id',
     * brand_id int(10) '品牌id',
     * group_id_array '店铺分类id 首尾用,隔开',
     * goods_type '实物或虚拟课程标志 1实物课程 0 虚拟课程 2 F码课程',
     * market_price '市场价',
     * price '课程原价格',
     * promotion_price '课程促销价格',
     * cost_price '成本价',
     * point_exchange_type '积分兑换类型 0 不支持积分兑换 1 积分+课程同时满足 2积分兑换或者直接购买3.只能积分兑换',
     * point_exchange '积分兑换',
     * give_point '购买课程赠送积分',
     * is_member_discount '参与会员折扣',
     * shipping_fee '运费 0为免运费',
     * shipping_fee_id '售卖区域id 物流模板id ns_order_shipping_fee 表id',
     * stock '课程库存',
     * max_buy '限购 0 不限购',
     * clicks'课程点击数量',
     * min_stock_alarm '库存预警值',
     * sales '销售数量',
     * collects '收藏数量',
     * star '好评星级',
     * evaluates '评价数',
     * shares '分享数',
     * province_id '一级地区id',
     * city_id '二级地区id',
     * picture '课程主图',
     * keywords '课程关键词',
     * introduction '课程简介',
     * description '课程详情',
     * QRcode '课程二维码',
     * code '商家编号',
     * is_stock_visible '页面不显示库存',
     *
     * state '课程状态 0下架，1正常，10违规（禁售）',
     * sale_date '上下架时间',
     * create_time '课程添加时间',
     * update_time '课程编辑时间',
     * sort '排序',
     * img_id_array '课程图片序列',
     * sku_img_array '课程sku应用图片列表 属性,属性值，图片ID',
     * match_point '实物与描述相符（根据评价计算）',
     * match_ratio '实物与描述相符（根据评价计算）百分比',
     * real_sales '实际销量',
     * goods_attribute '课程类型',
     * goods_spec_format '课程规格',
     *
     * @return \data\model\NsGoodsModel|number
     */
    public function addOrEditGoods($goods_id, $goods_name, $shopid, $category_id, $category_id_1, $category_id_2, $category_id_3, $supplier_id, $brand_id, $group_id_array, $goods_type, $market_price, $price, $cost_price, $point_exchange_type, $point_exchange, $give_point, $is_member_discount, $shipping_fee, $shipping_fee_id, $stock, $max_buy, $min_buy, $min_stock_alarm, $clicks, $sales, $collects, $star, $evaluates, $shares, $province_id, $city_id, $picture, $keywords, $introduction, $description, $QRcode, $code, $is_stock_visible, $is_hot, $is_recommend, $is_new, $sort, $image_array, $sku_array, $state, $sku_img_array, $goods_attribute_id, $goods_attribute, $goods_spec_format, $goods_weight, $goods_volume, $shipping_fee_type, $extend_category_id, $sku_picture_values, $virtual_goods_type_data, $production_date, $shelf_life, $ladder_preference, $goods_video_address, $pc_custom_template, $wap_custom_template, $max_use_point, $is_open_presell, $presell_delivery_type, $presell_price, $presell_time, $presell_day, $goods_unit,$goods_kind,$allow_delete)
    {
        Cache::tag("niu_goods_group")->clear();
        Cache::tag("niu_goods_category_block")->clear();
        Cache::tag("niu_goods")->clear();
        $error = 0;
        $category_list = $this->getGoodsCategoryId($category_id);
        // 1级扩展分类
        $extend_category_id_1s = "";
        // 2级扩展分类
        $extend_category_id_2s = "";
        // 3级扩展分类
        $extend_category_id_3s = "";
        if (! empty($extend_category_id)) {
            $extend_category_id_str = explode(",", $extend_category_id);
            foreach ($extend_category_id_str as $extend_id) {
                $extend_category_list = $this->getGoodsCategoryId($extend_id);
                
                if ($extend_category_id_1s === "") {
                    $extend_category_id_1s = $extend_category_list[0];
                } else {
                    $extend_category_id_1s = $extend_category_id_1s . "," . $extend_category_list[0];
                }
                if ($extend_category_id_2s === "") {
                    $extend_category_id_2s = $extend_category_list[1];
                } else {
                    $extend_category_id_2s = $extend_category_id_2s . "," . $extend_category_list[1];
                }
                if ($extend_category_id_3s === "") {
                    $extend_category_id_3s = $extend_category_list[2];
                } else {
                    $extend_category_id_3s = $extend_category_id_3s . "," . $extend_category_list[2];
                }
            }
        }
        $this->goods->startTrans();
        try {
            $data_goods = array(
                'goods_name' => $goods_name,
                'shop_id' => $shopid,
                'category_id' => $category_id,
                'category_id_1' => $category_list[0],
                'category_id_2' => $category_list[1],
                'category_id_3' => $category_list[2],
                'supplier_id' => $supplier_id,
                'brand_id' => $brand_id,
                'group_id_array' => $group_id_array,
                'goods_type' => $goods_type,
                'market_price' => $market_price,
                'price' => $price,
                'promotion_price' => $price,
                'cost_price' => $cost_price,
                'point_exchange_type' => $point_exchange_type,
                'point_exchange' => $point_exchange,
                'give_point' => $give_point,
                'is_member_discount' => $is_member_discount,
                'shipping_fee' => $shipping_fee,
                'shipping_fee_id' => $shipping_fee_id,
                'stock' => $stock,
                'max_buy' => $max_buy,
                'min_buy' => $min_buy,
                'min_stock_alarm' => $min_stock_alarm,
                'clicks' => $clicks,
                'sales' => $sales,
                'collects' => $collects,
                'star' => $star,
                'evaluates' => $evaluates,
                'shares' => $shares,
                'province_id' => $province_id,
                'city_id' => $city_id,
                'picture' => $picture,
                'keywords' => $keywords,
                'introduction' => $introduction,
                'description' => $description,
                'QRcode' => $QRcode,
                'code' => $code,
                'is_stock_visible' => $is_stock_visible,
                
                // 'is_hot' => $is_hot,
                // 'is_recommend' => $is_recommend,
                // 'is_new' => $is_new,
                'sort' => $sort,
                'img_id_array' => $image_array,
                'state' => $state,
                'sku_img_array' => $sku_img_array,
                'goods_attribute_id' => $goods_attribute_id,
                'goods_spec_format' => $goods_spec_format,
                'goods_weight' => $goods_weight,
                'goods_volume' => $goods_volume,
                'shipping_fee_type' => $shipping_fee_type,
                'extend_category_id' => $extend_category_id,
                'extend_category_id_1' => $extend_category_id_1s,
                'extend_category_id_2' => $extend_category_id_2s,
                'extend_category_id_3' => $extend_category_id_3s,
                
                // 'virtual_goods_type_id' => $virtual_goods_type_id,
                'production_date' => strtotime($production_date),
                'shelf_life' => $shelf_life,
                'goods_video_address' => $goods_video_address,
                'pc_custom_template' => $pc_custom_template,
                'wap_custom_template' => $wap_custom_template,
                'max_use_point' => $max_use_point,
                'is_open_presell' => $is_open_presell,
                'presell_time' => getTimeTurnTimeStamp($presell_time),
                'presell_day' => $presell_day,
                'presell_delivery_type' => $presell_delivery_type,
                'presell_price' => $presell_price,
                "goods_unit" => $goods_unit,
                "goods_kind" => $goods_kind,
                'allow_delete'=>$allow_delete
            );
            $_SESSION['goods_spec_format'] = $goods_spec_format;
            // 课程保存之前钩子
            hook("goodsSaveBefore", $data_goods);
            if ($goods_id == 0) {
                $data_goods['create_time'] = time();
                $data_goods['sale_date'] = time();
                $res = $this->goods->save($data_goods);
                // 课程保存成功钩子
                $data_goods['goods_id'] = $this->goods->goods_id;
                hook("goodsSaveSuccess", $data_goods);
                $goods_id = $this->goods->goods_id;
                $this->addUserLog($this->uid, 1, '课程', '添加课程', '添加课程:' . $goods_name);
                // 添加sku
                if (! empty($sku_array)) {
                    $sku_list_array = explode('§', $sku_array);
                    foreach ($sku_list_array as $k => $v) {
                        $res = $this->addOrUpdateGoodsSkuItem($this->goods->goods_id, $v);
                        if (! $res) {
                            $error = 1;
                        }
                    }
                    // sku图片添加
                    $sku_picture_array = array();
                    if (! empty($sku_picture_values)) {
                        $sku_picture_array = json_decode($sku_picture_values, true);
                        foreach ($sku_picture_array as $k => $v) {
                            $res = $this->addGoodsSkuPicture($shopid, $goods_id, $v["spec_id"], $v["spec_value_id"], $v["img_ids"]);
                            if (! $res) {
                                $error = 1;
                            }
                        }
                    }
                } else {
                    $goods_sku = new NsGoodsSkuModel();
                    
                    // 添加一条skuitem
                    $sku_data = array(
                        'goods_id' => $this->goods->goods_id,
                        'sku_name' => '',
                        'market_price' => $market_price,
                        'price' => $price,
                        'promote_price' => $price,
                        'cost_price' => $cost_price,
                        'stock' => $stock,
                        'picture' => 0,
                        'code' => $code,
                        'QRcode' => '',
                        'create_date' => time()
                    );
                    $res = $goods_sku->save($sku_data);
                    if (! $res) {
                        $error = 1;
                    }
                }
            } else {
                $data_goods['update_time'] = time();
                $res = $this->goods->save($data_goods, [
                    'goods_id' => $goods_id
                ]);
                $this->addUserLog($this->uid, 1, '课程', '修改课程', '修改课程:' . $goods_name);
                $data_goods['goods_id'] = $goods_id;
                hook("goodsSaveSuccess", $data_goods);
                if (! empty($sku_array)) {
                    $sku_list_array = explode('§', $sku_array);
                    $this->deleteSkuItem($goods_id, $sku_list_array);
                    foreach ($sku_list_array as $k => $v) {
                        $res = $this->addOrUpdateGoodsSkuItem($goods_id, $v);
                        if (! $res) {
                            $error = 1;
                        }
                    }
                    
                    // 修改时先删除原来的规格图片
                    $this->deleteGoodsSkuPicture([
                        "goods_id" => $goods_id
                    ]);
                    
                    // sku图片添加
                    $sku_picture_array = array();
                    if (! empty($sku_picture_values)) {
                        $sku_picture_array = json_decode($sku_picture_values, true);
                        foreach ($sku_picture_array as $k => $v) {
                            $res = $this->addGoodsSkuPicture($shopid, $goods_id, $v["spec_id"], $v["spec_value_id"], $v["img_ids"]);
                            if (! $res) {
                                $error = 1;
                            }
                        }
                    }
                } else {
                    $sku_data = array(
                        'goods_id' => $goods_id,
                        'sku_name' => '',
                        'market_price' => $market_price,
                        'price' => $price,
                        'promote_price' => $price,
                        'cost_price' => $cost_price,
                        'stock' => $stock,
                        'picture' => 0,
                        'code' => $code,
                        'QRcode' => '',
                        'update_date' => time()
                    );
                    $goods_sku = new NsGoodsSkuModel();
                    $count = $goods_sku->getCount([
                        'goods_id' => $goods_id
                    ]); // 当前SKU没有则添加，否则修改
                        // 此处不管有没有sku 课程sku表中都会有一条数据
                    if ($count >= 1) {
                        
                        $retval = $goods_sku->destroy([
                            'goods_id' => $goods_id,
                            'attr_value_items' => array(
                                'NEQ',
                                ''
                            )
                        ]);
                        $res = $goods_sku->save($sku_data, [
                            'goods_id' => $goods_id
                        ]);
                    } else {
                        $res = $goods_sku->save($sku_data);
                    }
                }
            }
            
            if ($error == 0) {
                $this->goods->commit();
                return $goods_id;
            } else {
                $this->goods->rollback();
                return 0;
            }
        } catch (\Exception $e) {
            $this->goods->rollback();
            Log::write('复制课程出错--' . $e->getMessage());
            return $e->getMessage();
        }
        return 0;
        
        // TODO Auto-generated method stub
    }

    /**
     * 获取单个课程的sku属性
     *
     * @ERROR!!!
     *
     * @see \data\api\IGoods::getGoodsSkuAll()
     */
    public function getGoodsAttribute($goods_id)
    {
        // 查询课程主表
        $goods = new NsGoodsModel();
        $goods_detail = $goods->get($goods_id);
        $spec_list = array();
        if (! empty($goods_detail) && ! empty($goods_detail['goods_spec_format']) && $goods_detail['goods_spec_format'] != "[]") {
            $spec_list = json_decode($goods_detail['goods_spec_format'], true);
            if (! empty($spec_list)) {
                foreach ($spec_list as $k => $v) {
                    foreach ($v["value"] as $m => $t) {
                        if (empty($t["spec_show_type"])) {
                            $spec_list[$k]["value"][$m]["spec_show_type"] = 1;
                        }
                        
                        $spec_list[$k]["value"][$m]["picture"] = $this->getGoodsSkuPictureBySpecId($goods_id, $spec_list[$k]["value"][$m]['spec_id'], $spec_list[$k]["value"][$m]['spec_value_id']);
                    }
                }
            }
        }
        return $spec_list;
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::getGoodsSku()
     */
    public function getGoodsSku($goods_id)
    {
        $goods_sku = new NsGoodsSkuModel();
        $list = $goods_sku->get([
            'goods_id' => $goods_id
        ]);
        return $list;
        // TODO Auto-generated method stub
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::editGoodsSku()
     */
    public function ModifyGoodsSku($goods_id, $sku_list)
    {
        // TODO Auto-generated method stub
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::getGoodsImg()
     */
    public function getGoodsImg($goods_id)
    {
        // TODO Auto-generated method stub
        $goods_info = $this->goods->getInfo([
            'goods_id' => $goods_id
        ], 'picture');
        $pic_info = array();
        if (! empty($goods_info)) {
            $picture = new AlbumPictureModel();
            $pic_info['pic_cover'] = '';
            if (! empty($goods_info['picture'])) {
                $pic_info = $picture->get($goods_info['picture']);
            }
        }
        return $pic_info;
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::editGoodsOffline()
     */
    public function ModifyGoodsOffline($condition)
    {
        Cache::tag("niu_goods_group")->clear();
        Cache::tag("niu_goods_category_block")->clear();
        Cache::tag("niu_goods")->clear();
        $data = array(
            "state" => 0,
            'update_time' => time()
        );
        $result = $this->goods->save($data, "goods_id  in($condition)");
        if ($result > 0) {
            // 课程下架成功钩子
            hook("goodsOfflineSuccess", [
                'goods_id' => $condition
            ]);
            return SUCCESS;
        } else {
            return UPDATA_FAIL;
        }
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::editGoodsOnline()
     */
    public function ModifyGoodsOnline($condition)
    {
        Cache::tag("niu_goods_group")->clear();
        Cache::tag("niu_goods_category_block")->clear();
        Cache::tag("niu_goods")->clear();
        $data = array(
            "state" => 1,
            'update_time' => time()
        );
        $result = $this->goods->save($data, "goods_id  in($condition)");
        if ($result > 0) {
            // 课程上架成功钩子
            hook("goodsOnlineSuccess", [
                'goods_id' => $condition
            ]);
            return SUCCESS;
        } else {
            return UPDATA_FAIL;
        }
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::deleteGoods()
     */
    public function deleteGoods($goods_id)
    {
        Cache::tag("niu_goods_group")->clear();
        Cache::tag("niu_goods_category_block")->clear();
        Cache::tag("niu_goods")->clear();
        $this->goods->startTrans();
        try {
            // 课程删除之前钩子
            hook("goodsDeleteBefore", [
                'goods_id' => $goods_id
            ]);
            // 将课程信息添加到课程回收库中
            $this->addGoodsDeleted($goods_id);
            $condition = array(
                'shop_id' => $this->instance_id,
                'goods_id' => $goods_id
            );
            $res = $this->goods->destroy($goods_id);
            
            if ($res > 0) {
                $goods_id_array = explode(',', $goods_id);
                $goods_sku_model = new NsGoodsSkuModel();
                $goods_sku_picture = new NsGoodsSkuPictureModel();
                foreach ($goods_id_array as $k => $v) {
                    // 删除课程sku
                    $goods_sku_model->destroy([
                        'goods_id' => $v
                    ]);
                    // 删除规格图片
                    $goods_sku_picture->destroy([
                        'goods_id' => $v
                    ]);
                }
            }
            $this->goods->commit();
            if ($res > 0) {
                // 课程删除成功钩子
                hook("goodsDeleteSuccess", [
                    'goods_id' => $goods_id
                ]);
                return SUCCESS;
            } else {
                return DELETE_FAIL;
            }
        } catch (\Exception $e) {
            $this->goods->rollback();
            return DELETE_FAIL;
        }
    }

    /**
     * 课程删除以前 将课程挪到 回收站中
     *
     * @param unknown $goods_ids            
     */
    private function addGoodsDeleted($goods_ids)
    {
        $this->goods->startTrans();
        try {
            $goods_id_array = explode(',', $goods_ids);
            foreach ($goods_id_array as $k => $v) {
                // 得到课程的信息 备份课程
                $goods_info = $this->goods->get($v);
                $goods_delete_model = new NsGoodsDeletedModel();
                $goods_info = json_decode(json_encode($goods_info), true);
                $goods_delete_obj = $goods_delete_model->getInfo([
                    "goods_id" => $v
                ]);
                if (empty($goods_delete_obj)) {
                    $goods_info["update_time"] = time();
                    $goods_delete_model->save($goods_info);
                    // 课程的sku 信息备份
                    $goods_sku_model = new NsGoodsSkuModel();
                    $goods_sku_list = $goods_sku_model->getQuery([
                        "goods_id" => $v
                    ], "*", "");
                    foreach ($goods_sku_list as $goods_sku_obj) {
                        $goods_sku_deleted_model = new NsGoodsSkuDeletedModel();
                        $goods_sku_obj = json_decode(json_encode($goods_sku_obj), true);
                        $goods_sku_obj["update_date"] = time();
                        $goods_sku_deleted_model->save($goods_sku_obj);
                    }
                    // 课程的sku图片备份
                    $goods_sku_picture = new NsGoodsSkuPictureModel();
                    $goods_sku_picture_list = $goods_sku_picture->getQuery([
                        'goods_id' => $v
                    ], "*", "");
                    foreach ($goods_sku_picture_list as $goods_sku_picture_list_obj) {
                        $goods_sku_picture_delete = new NsGoodsSkuPictureDeleteModel();
                        $goods_sku_picture_list_obj = json_decode(json_encode($goods_sku_picture_list_obj), true);
                        $goods_sku_picture_delete->save($goods_sku_picture_list_obj);
                    }
                }
            }
            $this->goods->commit();
            return 1;
        } catch (\Exception $e) {
            $this->goods->rollback();
            return $e->getMessage();
        }
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::deleteGoodImages()
     */
    public function deleteGoodImages($goods_id)
    {
        // TODO Auto-generated method stub
    }

    /**
     * 查询课程的基础信息
     * 每次访问课程详情时，点击量都会发生变化，如果缓存了，则看不到点击量的变化。其他字段也可能会出现问题
     *
     * @param unknown $goods_id            
     */
    public function getBasisGoodsDetail($goods_id)
    {
        // $cache = Cache::tag("niu_goods")->get("getBasisGoodsDetail" . $goods_id);
        // if(empty($cache)){
        // 课程的基础信息
        $goods = new NsGoodsModel();
        $goods_detail = $goods->get($goods_id);
        if ($goods_detail == null) {
            return null;
        }
        
        // 查询课程sku
        $goods_sku = new NsGoodsSkuModel();
        $goods_sku_detail = $goods_sku->where('goods_id=' . $goods_id)->select();
        $goods_detail['sku_list'] = $goods_sku_detail;
        // sku多图数据
        $sku_picture_list = $this->getGoodsSkuPicture($goods_id);
        
        $goods_detail["sku_picture_list"] = $sku_picture_list;
        $goods_all_picture = array();
        foreach ($sku_picture_list as $picture_obj) {
            $spec_value_id = $picture_obj["spec_value_id"];
            $goods_all_picture[$spec_value_id] = $picture_obj;
        }
        
        // 课程规格
        $spec_list = json_decode($goods_detail['goods_spec_format'], true);
        if (! empty($spec_list)) {
            // 排序字段
            $sort = array(
                'field' => 'sort'
            );
            $arrSort = array();
            $album = new Album();
            foreach ($spec_list as $k => $v) {
                $spec_list[$k]['sort'] = 0;
                foreach ($v["value"] as $m => $t) {
                    if (empty($t["spec_show_type"])) {
                        $spec_list[$k]["value"][$m]["spec_show_type"] = 1;
                    }
                    $picture = 0;
                    $sku_img_array = $goods_all_picture[$spec_list[$k]["value"][$m]['spec_value_id']];
                    if (! empty($sku_img_array)) {
                        $array = explode(",", $sku_img_array['sku_img_array']);
                        $picture = $array[0];
                    }
                    // 查询SKU规格主图，没有返回0
                    $spec_list[$k]["value"][$m]["picture"] = $picture;
                    // 规格图片
                    // 判断规格数组中图片路径是id还是路径
                    if ($t["spec_show_type"] == 3) {
                        if (is_numeric($t["spec_value_data"])) {
                            $picture_detail = $album->getAlubmPictureDetail([
                                "pic_id" => $t["spec_value_data"]
                            ]);
                            if (! empty($picture_detail)) {
                                $spec_list[$k]["value"][$m]["picture_id"] = $picture_detail['pic_id'];
                                $spec_list[$k]["value"][$m]["spec_value_data"] = $picture_detail["pic_cover_micro"];
                                $spec_list[$k]["value"][$m]["spec_value_data_big_src"] = $picture_detail["pic_cover_big"];
                            } else {
                                $spec_list[$k]["value"][$m]["spec_value_data"] = '';
                                $spec_list[$k]["value"][$m]["spec_value_data_big_src"] = '';
                                $spec_list[$k]["value"][$m]["picture_id"] = 0;
                            }
                        } else {
                            $spec_list[$k]["value"][$m]["spec_value_data_big_src"] = $t["spec_value_data"];
                            $spec_list[$k]["value"][$m]["picture_id"] = 0;
                        }
                    }
                }
            }
            // 排序字段
            foreach ($spec_list as $uniqid => $row) {
                foreach ($row as $key => $value) {
                    $arrSort[$key][$uniqid] = $value;
                }
            }
            array_multisort($arrSort[$sort['field']], SORT_ASC, $spec_list);
        }
        $goods_detail['spec_list'] = $spec_list;
        // 查询图片表
        $goods_img = new AlbumPictureModel();
        $order = "instr('," . $goods_detail['img_id_array'] . ",',CONCAT(',',pic_id,','))"; // 根据 in里边的id 排序
        $goods_img_list = $goods_img->getQuery([
            'pic_id' => [
                "in",
                $goods_detail['img_id_array']
            ]
        ], '*', $order);
        if (trim($goods_detail['img_id_array']) != "") {
            $img_array = array();
            $img_temp_array = array();
            $img_array = explode(",", $goods_detail['img_id_array']);
            foreach ($img_array as $k => $v) {
                if (! empty($goods_img_list)) {
                    foreach ($goods_img_list as $t => $m) {
                        if ($m["pic_id"] == $v) {
                            $img_temp_array[] = $m;
                        }
                    }
                }
            }
        }
        $goods_picture = $goods_img->get($goods_detail['picture']);
        $goods_detail["img_temp_array"] = $img_temp_array;
        $goods_detail['img_list'] = $goods_img_list;
        $goods_detail['picture_detail'] = $goods_picture;
        
        // 查询分类名称
        $category_name = $this->getGoodsCategoryName($goods_detail['category_id_1'], $goods_detail['category_id_2'], $goods_detail['category_id_3']);
        $goods_detail['category_name'] = $category_name;
        
        // 扩展分类 去掉查询 检测影响
        $extend_category_array = array();
        $goods_detail['extend_category_name'] = "";
        $goods_detail['extend_category'] = $extend_category_array;
        
        // 查询课程类型相关信息
        if ($goods_detail['goods_attribute_id'] != 0) {
            $attribute_model = new NsAttributeModel();
            $attribute_info = $attribute_model->getInfo([
                'attr_id' => $goods_detail['goods_attribute_id']
            ], 'attr_name');
            $goods_detail['goods_attribute_name'] = $attribute_info['attr_name'];
        } else {
            $goods_detail['goods_attribute_name'] = '';
            $goods_detail['goods_attribute_list'] = array();
        }
        // 店铺名称
        $shop_model = new NsShopModel();
        $shop_name = $shop_model->getInfo(array(
            "shop_id" => $goods_detail["shop_id"]
        ), "shop_name");
        $goods_detail["shop_name"] = $shop_name["shop_name"];
        // 查询课程规格图片
        $album_picture = new AlbumPictureModel();
        foreach ($sku_picture_list as $k => $v) {
            if ($v["sku_img_array"] != "") {
                $spec_name = '';
                $spec_value_name = '';
                foreach ($spec_list as $t => $m) {
                    if ($m["spec_id"] == $v["spec_id"]) {
                        foreach ($m["value"] as $c => $b) {
                            if ($b["spec_value_id"] == $v["spec_value_id"]) {
                                $spec_name = $b["spec_name"];
                                $spec_value_name = $b["spec_value_name"];
                                break;
                            }
                        }
                        break;
                    }
                }
                $sku_picture_list[$k]["spec_name"] = $spec_name;
                $sku_picture_list[$k]["spec_value_name"] = $spec_value_name;
                $sku_picture_list[$k]["sku_picture_query"] = $sku_picture_list[$k]["album_picture_list"];
            } else {
                unset($sku_picture_list[$k]);
            }
        }
        sort($sku_picture_list);
        $goods_detail["sku_picture_array"] = $sku_picture_list;
        
        $goods_attribute_list = $goods_detail['goods_attribute_list'];
        $goods_attribute_list_new = array();
        foreach ($goods_attribute_list as $item) {
            $attr_value_name = '';
            foreach ($goods_attribute_list as $key => $item_v) {
                if ($item_v['attr_value_id'] == $item['attr_value_id']) {
                    $attr_value_name .= $item_v['attr_value_name'] . ',';
                    unset($goods_attribute_list[$key]);
                }
            }
            if (! empty($attr_value_name)) {
                array_push($goods_attribute_list_new, array(
                    'attr_value_id' => $item['attr_value_id'],
                    'attr_value' => $item['attr_value'],
                    'attr_value_name' => rtrim($attr_value_name, ',')
                ));
            }
        }
        
        $goods_detail['goods_attribute_list'] = $goods_attribute_list_new;
        
        if ($goods_detail['match_ratio'] == 0) {
            $goods_detail['match_ratio'] = 100;
        }
        if ($goods_detail['match_point'] == 0) {
            $goods_detail['match_point'] = 5;
        }
        // 处理小数
        $goods_detail['match_ratio'] = round($goods_detail['match_ratio'], 2);
        $goods_detail['match_point'] = round($goods_detail['match_point'], 2);
        
        // Cache::tag("niu_goods")->set("getBasisGoodsDetail" . $goods_id, $goods_detail);
        // }else{
        // $goods_detail=$cache;
        // }
        return $this->getBusinessGoodsInfo($goods_detail);
    }

    /**
     * 查询课程的业务数据
     */
    public function getBusinessGoodsInfo($goods_detail)
    {
        /**
         * *******************************************会员价格-start****************************************************
         */
        // 会员的折扣价格
        $goods_preference = new GoodsPreference();
        if (! empty($this->uid)) {
            $member_discount = $goods_preference->getMemberLevelDiscount($this->uid);
        } else {
            $member_discount = 1;
        }
        // 查询课程会员价
        if ($member_discount == 1) {
            $goods_detail['is_show_member_price'] = 0;
        } else {
            $goods_detail['is_show_member_price'] = 1;
        }
        $member_price = $member_discount * $goods_detail['price'];
        $goods_detail['member_price'] = sprintf("%.2f", $member_price);
        // 课程sku价格
        foreach ($goods_detail['sku_list'] as $k => $goods_sku) {
            $goods_detail['sku_list'][$k]['member_price'] = $goods_sku['price'] * $member_discount;
        }
        
        /**
         * *******************************************会员价格-end*********************************************************
         */
        
        // 查询课程单品活动信息
        $goods_preference = new GoodsPreference();
        $goods_promotion_info = $goods_preference->getGoodsPromote($goods_detail["goods_id"]);
        if (! empty($goods_promotion_info)) {
            $goods_discount_info = new NsPromotionDiscountModel();
            $goods_detail['promotion_detail'] = $goods_discount_info->getInfo([
                'discount_id' => $goods_detail['promote_id']
            ], 'start_time, end_time,discount_name');
        }
        // 判断活动内容是否为空
        if (! empty($goods_detail['promotion_detail'])) {
            $goods_detail['promotion_info'] = $goods_promotion_info;
        } else {
            $goods_detail['promotion_info'] = "";
        }
        
        // 查询课程团购信息
      
        $goods_detail['group_info'] = "";
        
        // 查询课程的已购数量
        if (! empty($this->uid)) {
            $orderGoods = new NsOrderGoodsModel();
            $num = 0;
            $num = $orderGoods->getSum([
                "goods_id" => $goods_detail["goods_id"],
                "buyer_id" => $this->uid,
                "order_status" => array(
                    "neq",
                    5
                )
            ], "num");
            $goods_detail["purchase_num"] = $num;
        } else {
            $goods_detail["purchase_num"] = 0;
        }
        return $goods_detail;
    }
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::getGoodsDetail()
     */
    public function getGoodsDetail($goods_id)
    {
        // 查询课程主表
        $goods = new NsGoodsModel();
        $goods_detail = $goods->get($goods_id);
        if ($goods_detail == null) {
            return null;
        }
        $goods_preference = new GoodsPreference();
        if (! empty($this->uid)) {
            $member_discount = $goods_preference->getMemberLevelDiscount($this->uid);
        } else {
            $member_discount = 1;
        }
        
        // 查询课程会员价
        if ($member_discount == 1) {
            $goods_detail['is_show_member_price'] = 0;
        } else {
            $goods_detail['is_show_member_price'] = 1;
        }
        $member_price = $member_discount * $goods_detail['price'];
        $goods_detail['member_price'] = $member_price;
        
        // sku多图数据
        $sku_picture_list = $this->getGoodsSkuPicture($goods_id);
        $goods_detail["sku_picture_list"] = $sku_picture_list;
        $goods_all_picture = array();
        foreach ($sku_picture_list as $picture_obj) {
            $spec_value_id = $picture_obj["spec_value_id"];
            $goods_all_picture[$spec_value_id] = $picture_obj;
        }

        // 查询课程sku表
        $goods_sku = new NsGoodsSkuModel();
        $goods_sku_detail = $goods_sku->where('goods_id=' . $goods_id)->select();
        
        foreach ($goods_sku_detail as $k => $goods_sku) {
            $goods_sku_detail[$k]['member_price'] = $goods_sku['price'] * $member_discount;
        }
        
        $goods_detail['sku_list'] = $goods_sku_detail;
        $spec_list = json_decode($goods_detail['goods_spec_format'], true);
        if (! empty($spec_list)) {
            foreach ($spec_list as $k => $v) {
                
                $spec_list[$k]['sort'] = 0;
                
                foreach ($v["value"] as $m => $t) {
                    if (empty($t["spec_show_type"])) {
                        $spec_list[$k]["value"][$m]["spec_show_type"] = 1;
                    }
                    $picture = 0;
                    $sku_img_array = $goods_all_picture[$spec_list[$k]["value"][$m]['spec_value_id']];
                    if (! empty($sku_img_array)) {
                        $array = explode(",", $sku_img_array['sku_img_array']);
                        $picture = $array[0];
                    }
                    // 查询SKU规格主图，没有返回0
                    $spec_list[$k]["value"][$m]["picture"] = $picture;
                    // $this->getGoodsSkuPictureBySpecId($goods_id, $spec_list[$k]["value"][$m]['spec_id'], $spec_list[$k]["value"][$m]['spec_value_id']);
                }
            }
        }
        
        $goods_detail['spec_list'] = $spec_list;
        // 查询图片表
        $goods_img = new AlbumPictureModel();
        $order = "instr('," . $goods_detail['img_id_array'] . ",',CONCAT(',',pic_id,','))"; // 根据 in里边的id 排序
        $goods_img_list = $goods_img->getQuery([
            'pic_id' => [
                "in",
                $goods_detail['img_id_array']
            ]
        ], '*', $order);
        if (trim($goods_detail['img_id_array']) != "") {
            $img_array = array();
            $img_temp_array = array();
            $img_array = explode(",", $goods_detail['img_id_array']);
            foreach ($img_array as $k => $v) {
                if (! empty($goods_img_list)) {
                    foreach ($goods_img_list as $t => $m) {
                        if ($m["pic_id"] == $v) {
                            $img_temp_array[] = $m;
                        }
                    }
                }
            }
        }
        $goods_picture = $goods_img->get($goods_detail['picture']);
        $goods_detail["img_temp_array"] = $img_temp_array;
        $goods_detail['img_list'] = $goods_img_list;
        $goods_detail['picture_detail'] = $goods_picture;
        // 查询分类名称
        $category_name = $this->getGoodsCategoryName($goods_detail['category_id_1'], $goods_detail['category_id_2'], $goods_detail['category_id_3']);
        $goods_detail['category_name'] = $category_name;
        // 扩展分类
        $extend_category_array = array();
        if (! empty($goods_detail['extend_category_id'])) {
            $extend_category_ids = $goods_detail['extend_category_id'];
            $extend_category_id_1s = $goods_detail['extend_category_id_1'];
            $extend_category_id_2s = $goods_detail['extend_category_id_2'];
            $extend_category_id_3s = $goods_detail['extend_category_id_3'];
            $extend_category_id_str = explode(",", $extend_category_ids);
            $extend_category_id_1s_str = explode(",", $extend_category_id_1s);
            $extend_category_id_2s_str = explode(",", $extend_category_id_2s);
            $extend_category_id_3s_str = explode(",", $extend_category_id_3s);
            foreach ($extend_category_id_str as $k => $v) {
                $extend_category_name = $this->getGoodsCategoryName($extend_category_id_1s_str[$k], $extend_category_id_2s_str[$k], $extend_category_id_3s_str[$k]);
                $extend_category_array[] = array(
                    "extend_category_name" => $extend_category_name,
                    "extend_category_id" => $v,
                    "extend_category_id_1" => $extend_category_id_1s_str[$k],
                    "extend_category_id_2" => $extend_category_id_2s_str[$k],
                    "extend_category_id_3" => $extend_category_id_3s_str[$k]
                );
            }
        }
        $goods_detail['extend_category_name'] = "";
        $goods_detail['extend_category'] = $extend_category_array;
        
        // 查询课程类型相关信息
        if ($goods_detail['goods_attribute_id'] != 0) {
            $attribute_model = new NsAttributeModel();
            $attribute_info = $attribute_model->getInfo([
                'attr_id' => $goods_detail['goods_attribute_id']
            ], 'attr_name');
            $goods_detail['goods_attribute_name'] = $attribute_info['attr_name'];
        } else {
            $goods_detail['goods_attribute_name'] = '';
            $goods_detail['goods_attribute_list'] = array();
        }
        // 查询课程单品活动信息
        $goods_preference = new GoodsPreference();
        $goods_promotion_info = $goods_preference->getGoodsPromote($goods_id);
        if (! empty($goods_promotion_info)) {
            $goods_discount_info = new NsPromotionDiscountModel();
            $goods_detail['promotion_detail'] = $goods_discount_info->getInfo([
                'discount_id' => $goods_detail['promote_id']
            ], 'start_time, end_time,discount_name');
        }
        // 判断活动内容是否为空
        if (! empty($goods_detail['promotion_detail'])) {
            $goods_detail['promotion_info'] = $goods_promotion_info;
        } else {
            $goods_detail['promotion_info'] = "";
        }
        
        $shop_model = new NsShopModel();
        $shop_name = $shop_model->getInfo(array(
            "shop_id" => $goods_detail["shop_id"]
        ), "shop_name");
        $goods_detail["shop_name"] = $shop_name["shop_name"];
        // 查询课程规格图片
        $goos_sku_picture = new NsGoodsSkuPictureModel();
        $goos_sku_picture_query = $goos_sku_picture->getQuery([
            "goods_id" => $goods_id
        ], "*", '');
        $album_picture = new AlbumPictureModel();
        foreach ($goos_sku_picture_query as $k => $v) {
            if ($v["sku_img_array"] != "") {
                $spec_name = '';
                $spec_value_name = '';
                foreach ($spec_list as $t => $m) {
                    if ($m["spec_id"] == $v["spec_id"]) {
                        foreach ($m["value"] as $c => $b) {
                            if ($b["spec_value_id"] == $v["spec_value_id"]) {
                                $spec_name = $b["spec_name"];
                                $spec_value_name = $b["spec_value_name"];
                            }
                        }
                    }
                }
                $goos_sku_picture_query[$k]["spec_name"] = $spec_name;
                $goos_sku_picture_query[$k]["spec_value_name"] = $spec_value_name;
                $tmp_img_array = $album_picture->getQuery([
                    "pic_id" => [
                        "in",
                        $v["sku_img_array"]
                    ]
                ], "*", '');
                $pic_id_array = explode(',', (string) $v["sku_img_array"]);
                $goos_sku_picture_query[$k]["sku_picture_query"] = array();
                $sku_picture_query_array = array();
                foreach ($pic_id_array as $t => $m) {
                    foreach ($tmp_img_array as $q => $z) {
                        if ($m == $z["pic_id"]) {
                            $sku_picture_query_array[] = $z;
                        }
                    }
                }
                $goos_sku_picture_query[$k]["sku_picture_query"] = $sku_picture_query_array;
                // $goos_sku_picture_query[$k]["sku_picture_query"] = $album_picture->getQuery(["pic_id"=>["in",$v["sku_img_array"]]], "*", '');
            } else {
                unset($goos_sku_picture_query[$k]);
            }
        }
        sort($goos_sku_picture_query);
        $goods_detail["sku_picture_array"] = $goos_sku_picture_query;
        // 查询课程的已购数量
        $orderGoods = new NsOrderGoodsModel();
        $num = 0;
        $num = $orderGoods->getSum([
            "goods_id" => $goods_id,
            "buyer_id" => $this->uid,
            "order_status" => array(
                "neq",
                5
            )
        ], "num");
        $goods_detail["purchase_num"] = $num;
        
        return $goods_detail;
    }

    /**
     * 查询sku多图数据
     *
     * @ERROR!!!
     *
     * @see \data\api\IGoods::getGoodsSkuPicture()
     */
    public function getGoodsSkuPicture($goods_id)
    {
        $goods_sku = new NsGoodsSkuPictureModel();
        $sku_picture_list = $goods_sku->getQuery([
            "goods_id" => $goods_id
        ], "*", "");
        $total_sku_img_array = array();
        foreach ($sku_picture_list as $k => $v) {
            $sku_img_ids = $v["sku_img_array"];
            $sku_img_array = explode(",", $sku_img_ids);
            if (! empty($total_sku_img_array)) {
                $total_sku_img_array = array_keys(array_flip($total_sku_img_array) + array_flip($sku_img_array));
            } else {
                $total_sku_img_array = $sku_img_array;
            }
        }
        $total_sku_img_ids = implode(",", $total_sku_img_array);
        $picture_model = new AlbumPictureModel();
        if (! empty($total_sku_img_ids)) {
            $picture_list = $picture_model->getQuery("pic_id in ($total_sku_img_ids)", "*", "");
        } else {
            $picture_list = '';
        }
        
        foreach ($sku_picture_list as $k => $v) {
            $sku_img_ids = $v["sku_img_array"];
            $sku_img_array = explode(",", $sku_img_ids);
            $album_picture_list = array();
            foreach ($picture_list as $picture_obj) {
                $curr_pic_id = $picture_obj["pic_id"];
                if (in_array($curr_pic_id, $sku_img_array)) {
                    $album_picture_list[] = $picture_obj;
                }
            }
            $sku_picture_list[$k]["album_picture_list"] = $album_picture_list;
        }
        return $sku_picture_list;
    }

    /**
     * 根据课程id、规格id、规格值id查询
     *
     * @ERROR!!!
     *
     * @see \data\api\IGoods::getGoodsSkuPictureBySpecId()
     */
    public function getGoodsSkuPictureBySpecId($goods_id, $spec_id, $spec_value_id)
    {
        $picture = 0;
        
        $goods_sku = new NsGoodsSkuPictureModel();
        $sku_img_array = $goods_sku->getInfo([
            "goods_id" => $goods_id,
            "spec_id" => $spec_id,
            "spec_value_id" => $spec_value_id,
            "shop_id" => $this->instance_id
        ], "sku_img_array");
        if (! empty($sku_img_array)) {
            $array = explode(",", $sku_img_array['sku_img_array']);
            $picture = $array[0];
        }
        return $picture;
    }

    
    /**
     * 添加课程sku列表
     *
     * @param unknown $goods_id            
     * @param unknown $sku_item_array            
     * @return Ambigous <number, \think\false, boolean, string>
     */
    private function addOrUpdateGoodsSkuItem($goods_id, $sku_item_array)
    {
        $sku_item = explode('¦', $sku_item_array);
        $goods_sku = new NsGoodsSkuModel();
        $sku_name = $this->createSkuName($sku_item[0]);
        $condition = array(
            'goods_id' => $goods_id,
            'attr_value_items' => $sku_item[0]
        );
        $sku_count = $goods_sku->where($condition)->find();
        
        if (empty($sku_count)) {
            $data = array(
                'goods_id' => $goods_id,
                'sku_name' => $sku_name,
                'attr_value_items' => $sku_item[0],
                'attr_value_items_format' => $sku_item[0],
                'price' => $sku_item[1],
                'promote_price' => $sku_item[1],
                'market_price' => $sku_item[2],
                'cost_price' => $sku_item[3],
                'stock' => $sku_item[4],
                'picture' => 0,
                'code' => $sku_item[5],
                'QRcode' => '',
                'create_date' => time()
            );
            $goods_sku->save($data);
            return $goods_sku->sku_id;
        } else {
            $data = array(
                'goods_id' => $goods_id,
                'sku_name' => $sku_name,
                'price' => $sku_item[1],
                'promote_price' => $sku_item[1],
                'market_price' => $sku_item[2],
                'cost_price' => $sku_item[3],
                'stock' => $sku_item[4],
                'code' => $sku_item[5],
                'QRcode' => '',
                'update_date' => time()
            );
            $res = $goods_sku->save($data, [
                'sku_id' => $sku_count['sku_id']
            ]);
            return $res;
        }
    }

    private function deleteSkuItem($goods_id, $sku_list_array)
    {
        $sku_item_list_array = array();
        foreach ($sku_list_array as $k => $sku_item_array) {
            $sku_item = explode('¦', $sku_item_array);
            $sku_item_list_array[] = $sku_item[0];
        }
        $goods_sku = new NsGoodsSkuModel();
        $list = $goods_sku->where('goods_id=' . $goods_id)->select();
        if (! empty($list)) {
            foreach ($list as $k => $v) {
                if (! in_array($v['attr_value_items'], $sku_item_list_array)) {
                    $goods_sku->destroy($v['sku_id']);
                }
            }
        }
    }

    /**
     * 组装sku name
     *
     * @param unknown $pvs            
     * @return string
     */
    private function createSkuName($pvs)
    {
        $name = '';
        $pvs_array = explode(';', $pvs);
        foreach ($pvs_array as $k => $v) {
            $value = explode(':', $v);
            $prop_id = $value[0];
            $prop_value = $value[1];
            $goods_spec_value_model = new NsGoodsSpecValueModel();
            $value_name = $this->getUserSkuName($prop_value);
            $name = $name . $value_name . ' ';
        }
        return $name;
    }

    /**
     * 获取用户自定义的规格值名称
     *
     * @param unknown $spec_id            
     */
    private function getUserSkuName($spec_id)
    {
        $sku_name = "";
        $goods_spec_format = $_SESSION['goods_spec_format'];
        if (! empty($goods_spec_format)) {
            $goods_spec_format = json_decode($goods_spec_format, true);
            foreach ($goods_spec_format as $spec_value) {
                foreach ($spec_value["value"] as $spec) {
                    if ($spec_id == $spec['spec_value_id']) {
                        $sku_name = $spec['spec_value_name'];
                    }
                }
            }
        }
        return $sku_name;
    }

    /**
     * 根据当前分类ID查询课程分类的三级分类ID
     *
     * @param unknown $category_id            
     */
    private function getGoodsCategoryId($category_id)
    {
        // 获取分类层级
        $goods_category = new NsGoodsCategoryModel();
        $info = $goods_category->get($category_id);
        if ($info['level'] == 1) {
            return array(
                $category_id,
                0,
                0
            );
        }
        if ($info['level'] == 2) {
            // 获取父级
            return array(
                $info['pid'],
                $category_id,
                0
            );
            ;
        }
        if ($info['level'] == 3) {
            $info_parent = $goods_category->get($info['pid']);
            // 获取父级
            return array(
                $info_parent['pid'],
                $info['pid'],
                $category_id
            );
            ;
        }
    }

    /**
     * 根据当前课程分类组装分类名称
     *
     * @param unknown $category_id_1            
     * @param unknown $category_id_2            
     * @param unknown $category_id_3            
     */
    private function getGoodsCategoryName($category_id_1, $category_id_2, $category_id_3)
    {
        $name = '';
        $goods_category = new NsGoodsCategoryModel();
        $info_1 = $goods_category->getInfo([
            'category_id' => $category_id_1
        ], 'category_name');
        $info_2 = $goods_category->getInfo([
            'category_id' => $category_id_2
        ], 'category_name');
        $info_3 = $goods_category->getInfo([
            'category_id' => $category_id_3
        ], 'category_name');
        if (! empty($info_1['category_name'])) {
            $name = $info_1['category_name'] . ' > ';
        }
        if (! empty($info_2['category_name'])) {
            $name = $name . '' . $info_2['category_name'] . ' > ';
        }
        if (! empty($info_3['category_name'])) {
            $name = $name . '' . $info_3['category_name'];
        }
        return $name;
    }

    /**
     * 获取条件查询出课程
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::getSearchGoodsList()
     */
    public function getSearchGoodsList($page_index = 1, $page_size = 0, $condition = '', $order = '', $field = '*')
    {
        $result = $this->goods->pageQuery($page_index, $page_size, $condition, $order, $field);
        foreach ($result['data'] as $k => $v) {
            $picture = new AlbumPictureModel();
            $pic_info = array();
            $pic_info['pic_cover'] = '';
            if (! empty($v['picture'])) {
                $pic_info = $picture->get($v['picture']);
            }
            $result['data'][$k]['picture_info'] = $pic_info;
        }
        return $result;
    }

    /**
     * 修改课程 推荐 1=热销 2=推荐 3=新品
     */
    public function ModifyGoodsRecommend($goods_ids, $goods_type)
    {
        $goods = new NsGoodsModel();
        $goods->startTrans();
        try {
            $goods_id_array = explode(',', $goods_ids);
            $goods_type = explode(',', $goods_type);
            $data = array(
                "is_new" => $goods_type[0],
                "is_recommend" => $goods_type[1],
                "is_hot" => $goods_type[2]
            );
            foreach ($goods_id_array as $k => $v) {
                $goods = new NsGoodsModel();
                $goods->save($data, [
                    'goods_id' => $v
                ]);
            }
            $goods->commit();
            return 1;
        } catch (\Exception $e) {
            $goods->rollback();
            return $e->getMessage();
        }
    }

    /**
     * 获取课程可得积分
     *
     * @param unknown $goods_id            
     */
    public function getGoodsGivePoint($goods_id)
    {
        $goods = new NsGoodsModel();
        $point_info = $goods->getInfo([
            'goods_id' => $goods_id
        ], 'give_point');
        return $point_info['give_point'];
    }

    /**
     * 通过课程skuid查询goods_id
     *
     * @param unknown $sku_id            
     */
    public function getGoodsId($sku_id)
    {
        $goods_sku = new NsGoodsSkuModel();
        $sku_info = $goods_sku->getInfo([
            'sku_id' => $sku_id
        ], 'goods_id');
        return $sku_info['goods_id'];
    }

    /**
     * 获取购物车中项目，根据cartid
     *
     * @param unknown $carts            
     */
    public function getCartList($carts)
    {
        $cart = new NsCartModel();
        $cart_list = $cart->getQuery([
            'buyer_id' => $this->uid
        ], '*', 'cart_id');
        $cart_array = explode(',', $carts);
        $list = array();
        foreach ($cart_list as $k => $v) {
            $goods = new NsGoodsModel();
            $goods_info = $goods->getInfo([
                'goods_id' => $v['goods_id']
            ], 'max_buy,state,point_exchange_type,point_exchange,max_use_point');
            // 获取课程sku信息
            $goods_sku = new NsGoodsSkuModel();
            $sku_info = $goods_sku->getInfo([
                'sku_id' => $v['sku_id']
            ], 'stock');
            if (empty($sku_info)) {
                $cart->destroy([
                    'buyer_id' => $this->uid,
                    'sku_id' => $v['sku_id']
                ]);
                continue;
            } else {
                if ($sku_info['stock'] == 0) {
                    $cart->destroy([
                        'buyer_id' => $this->uid,
                        'sku_id' => $v['sku_id']
                    ]);
                    continue;
                }
            }
            
            $v['stock'] = $sku_info['stock'];
            $v['max_buy'] = $goods_info['max_buy'];
            $v['point_exchange_type'] = $goods_info['point_exchange_type'];
            $v['point_exchange'] = $goods_info['point_exchange'];
            if ($goods_info['state'] != 1) {
                $this->cartDelete($v['cart_id']);
                unset($v);
            }
            $num = $v['num'];
            if ($goods_info['max_buy'] != 0 && $goods_info['max_buy'] < $v['num']) {
                $num = $goods_info['max_buy'];
            }
            
            if ($sku_info['stock'] < $num) {
                $num = $sku_info['stock'];
            }
            if ($num != $v['num']) {
                // 更新购物车
                $this->cartAdjustNum($v['cart_id'], $sku_info['stock']);
                $v['num'] = $num;
            }
            $v["max_use_point"] = $goods_info["max_use_point"] * $num;
            // 获取阶梯优惠后的价格
            $v["price"] = $this->getGoodsLadderPreferentialInfo($v["goods_id"], $v['num'], $v['price']);
            // 获取图片信息
            $picture = new AlbumPictureModel();
            $picture_info = $picture->get($v['goods_picture']);
            $v['picture_info'] = $picture_info;
            if (in_array($v['cart_id'], $cart_array)) {
                $list[] = $v;
            }
        }
        return $list;
    }

    /**
     * 获取购物车
     *
     * @param unknown $uid            
     */
    public function getCart($uid, $shop_id = 0)
    {
        if ($uid > 0) {
            $cart = new NsCartModel();
            $cart_goods_list = null;
            if ($shop_id == 0) {
                $cart_goods_list = $cart->getQuery([
                    'buyer_id' => $this->uid
                ], '*', '');
            } else {
                
                $cart_goods_list = $cart->getQuery([
                    'buyer_id' => $this->uid,
                    'shop_id' => $shop_id
                ], '*', '');
            }
        } else {
            $cart_goods_list = cookie('cart_array');
            if (empty($cart_goods_list)) {
                $cart_goods_list = array();
            } else {
                $cart_goods_list = json_decode($cart_goods_list, true);
            }
        }
        $goods_id_array = array();
        if (! empty($cart_goods_list)) {
            foreach ($cart_goods_list as $k => $v) {
                $goods = new NsGoodsModel();
                $goods_info = $goods->getInfo([
                    'goods_id' => $v['goods_id']
                ], 'sales,max_buy,state,point_exchange_type,point_exchange,goods_name,price, picture, min_buy ');
                // 获取课程sku信息
                $goods_sku = new NsGoodsSkuModel();
                $sku_info = $goods_sku->getInfo([
                    'sku_id' => $v['sku_id']
                ], 'stock, price, sku_name, promote_price');
                // 将goods_id 存放到数组中
                $goods_id_array[] = $v["goods_id"];
                // 验证课程或sku是否存在,不存在则从购物车移除
                if ($uid > 0) {
                    if (empty($goods_info)) {
                        $cart->destroy([
                            'goods_id' => $v['goods_id'],
                            'buyer_id' => $uid
                        ]);
                        unset($cart_goods_list[$k]);
                        continue;
                    }
                    if (empty($sku_info)) {
                        unset($cart_goods_list[$k]);
                        $cart->destroy([
                            'buyer_id' => $uid,
                            'sku_id' => $v['sku_id']
                        ]);
                        continue;
                    }
                } else {
                    if (empty($goods_info)) {
                        unset($cart_goods_list[$k]);
                        $this->cartDelete($v['cart_id']);
                        continue;
                    }
                    if (empty($sku_info)) {
                        unset($cart_goods_list[$k]);
                        $this->cartDelete($v['cart_id']);
                        continue;
                    }
                }
                // exit();
                // 为cookie信息完善课程和sku信息
                if ($uid > 0) {
                    // 查看用户会员价
                    $goods_preference = new GoodsPreference();
                    if (! empty($this->uid)) {
                        $member_discount = $goods_preference->getMemberLevelDiscount($uid);
                    } else {
                        $member_discount = 1;
                    }
                    $member_price = round($member_discount * $sku_info['price'], 2);
                    if ($member_price > $sku_info["promote_price"]) {
                        $price = $sku_info["promote_price"];
                    } else {
                        $price = $member_price;
                    }
                    $update_data = array(
                        "goods_name" => $goods_info["goods_name"],
                        "sku_name" => $sku_info["sku_name"],
                        "goods_picture" => $v['goods_picture'], // $goods_info["picture"],
                        "price" => $price
                    );
                    // 更新数据
                    $cart->save($update_data, [
                        "cart_id" => $v["cart_id"]
                    ]);
                    $cart_goods_list[$k]["price"] = $price;
                    $cart_goods_list[$k]["goods_name"] = $goods_info["goods_name"];
                    $cart_goods_list[$k]["sku_name"] = $sku_info["sku_name"];
                    $cart_goods_list[$k]["goods_picture"] = $v['goods_picture']; // $goods_info["picture"];
                } else {
                    $cart_goods_list[$k]["price"] = $sku_info["promote_price"];
                    $cart_goods_list[$k]["goods_name"] = $goods_info["goods_name"];
                    $cart_goods_list[$k]["sku_name"] = $sku_info["sku_name"];
                    $cart_goods_list[$k]["goods_picture"] = $v['goods_picture']; // $goods_info["picture"];
                }
                
                $cart_goods_list[$k]['stock'] = $sku_info['stock'];
                $cart_goods_list[$k]['max_buy'] = $goods_info['max_buy'];
                $cart_goods_list[$k]['min_buy'] = $goods_info['min_buy'];
                $cart_goods_list[$k]['sales'] = $goods_info['sales'];
                $cart_goods_list[$k]['point_exchange_type'] = $goods_info['point_exchange_type'];
                $cart_goods_list[$k]['point_exchange'] = $goods_info['point_exchange'];
                
                if ($goods_info['state'] != 1) {
                    unset($cart_goods_list[$k]);
                    // 更新cookie购物车
                    $this->cartDelete($v['cart_id']);
                    continue;
                }
                $num = $v['num'];
                if ($goods_info['max_buy'] != 0 && $goods_info['max_buy'] < $v['num']) {
                    $num = $goods_info['max_buy'];
                }
                if ($sku_info['stock'] < $num) {
                    $num = $sku_info['stock'];
                }
                // 课程最小购买数大于现购买数
                if ($goods_info['min_buy'] > 0 && $num < $goods_info['min_buy']) {
                    $num = $goods_info['min_buy'];
                }
                // 课程最小购买数大于现有库存
                if ($goods_info['min_buy'] > $sku_info['stock']) {
                    unset($cart_goods_list[$k]);
                    // 更新cookie购物车
                    $this->cartDelete($v['cart_id']);
                    continue;
                }
                if ($num != $v['num']) {
                    // 更新购物车
                    $cart_goods_list[$k]['num'] = $num;
                    $this->cartAdjustNum($v['cart_id'], $num);
                }
                
                $cart_goods_list[$k]["promotion_price"] = $cart_goods_list[$k]["price"];
                // 阶梯优惠后的价格
                $cart_goods_list[$k]["price"] = $this->getGoodsLadderPreferentialInfo($v['goods_id'], $num, $cart_goods_list[$k]["price"]);
            }
            // 为购物车图片
            foreach ($cart_goods_list as $k => $v) {
                $picture = new AlbumPictureModel();
                $picture_info = $picture->get($v['goods_picture']);
                $cart_goods_list[$k]['picture_info'] = $picture_info;
            }
            sort($cart_goods_list);
            // $cart_goods_list[0]["goods_id_array"] = $goods_id_array;
        }
        return $cart_goods_list;
    }

    /**
     * 添加购物车(non-PHPdoc)
     *
     * @see \data\api\IGoods::addCart()
     */
    public function addCart($uid, $shop_id, $shop_name, $goods_id, $goods_name, $sku_id, $sku_name, $price, $num, $picture, $bl_id)
    {
        $retval = array(
            'code' => 0,
            "message" => ""
        );
        // 课程限购，判断是否允许添加到购物车
        $goods_purchase_restriction = array(
            "code" => 1,
            "message" => "添加购物车成功"
        );
        if ($uid > 0) {
            $cart = new NsCartModel();
            $condition = array(
                'buyer_id' => $uid,
                'sku_id' => $sku_id
            );
            
            // 查询当前用户所购买的课程限购，是否允许添加到购物车中
            $goods_purchase_restriction = $this->getGoodsPurchaseRestrictionForCurrentUser($goods_id, $num);
            if ($goods_purchase_restriction['code'] == 0) {
                $retval = $goods_purchase_restriction;
                return $retval;
            }
            
            $count = $cart->where($condition)->count();
            if ($count == 0 || empty($count)) {
                $data = array(
                    'buyer_id' => $uid,
                    'shop_id' => $shop_id,
                    'shop_name' => $shop_name,
                    'goods_id' => $goods_id,
                    'goods_name' => $goods_name,
                    'sku_id' => $sku_id,
                    'sku_name' => $sku_name,
                    'price' => $price,
                    'num' => $num,
                    'goods_picture' => $picture,
                    'bl_id' => $bl_id
                );
                $cart->save($data);
                $retval['code'] = $cart->cart_id;
                $retval['message'] = lang("added_cart_success");
            } else {
                $cart = new NsCartModel();
                // 查询课程限购
                $goods = new NsGoodsModel();
                $get_num = $cart->getInfo($condition, 'cart_id,num');
                $max_buy = $goods->getInfo([
                    'goods_id' => $goods_id
                ], 'max_buy');
                $new_num = $num + $get_num['num'];
                if ($max_buy['max_buy'] != 0) {
                    
                    if ($new_num > $max_buy['max_buy']) {
                        $new_num = $max_buy['max_buy'];
                    }
                }
                $data = array(
                    'num' => $new_num
                );
                $res = $cart->save($data, $condition);
                if ($res) {
                    $retval['code'] = $get_num['cart_id'];
                    $retval['message'] = lang("added_cart_success");
                }
            }
        } else {
            
            // 未登录的情况下添加购物车
            $cart_array = cookie('cart_array');
            $data = array(
                'shop_id' => $shop_id,
                'goods_id' => $goods_id,
                'sku_id' => $sku_id,
                'num' => $num,
                'goods_picture' => $picture
            );
            if (! empty($cart_array)) {
                $cart_array = json_decode($cart_array, true);
                $tmp_array = array();
                foreach ($cart_array as $k => $v) {
                    $tmp_array[] = $v['cart_id'];
                }
                $cart_id = max($tmp_array) + 1;
                $is_have = true;
                foreach ($cart_array as $k => $v) {
                    if ($v["goods_id"] == $goods_id && $v["sku_id"] == $sku_id) {
                        $is_have = false;
                        $cart_array[$k]["num"] = $data["num"] + $v["num"];
                    }
                }
                
                if ($is_have) {
                    $data["cart_id"] = $cart_id;
                    $cart_array[] = $data;
                }
                // 检查课程限购，是否允许添加到购物车中
                $goods_purchase_restriction = $this->getGoodsPurchaseRestrictionForCurrentUser($goods_id, $num);
            } else {
                $data["cart_id"] = 1;
                $cart_array[] = $data;
            }
            try {
                
                // 课程限购了，不允许添加
                if ($goods_purchase_restriction['code'] == 0) {
                    $retval = $goods_purchase_restriction;
                } else {
                    $cart_array_string = json_encode($cart_array);
                    cookie('cart_array', $cart_array_string, 3600);
                    $retval['code'] = 1;
                    $retval['message'] = lang("added_cart_success");
                }
            } catch (\Exception $e) {
                
                $retval['code'] = 0;
                $retval['message'] = lang("failed_to_add_cart");
            }
        }
        return $retval;
    }

    /**
     * 购物车数量修改(non-PHPdoc)
     *
     * @see \data\api\IGoods::cartAdjustNum()
     */
    public function cartAdjustNum($cart_id, $num)
    {
        if ($this->uid > 0) {
            $cart = new NsCartModel();
            $data = array(
                'num' => $num
            );
            $retval = $cart->save($data, [
                'cart_id' => $cart_id
            ]);
            return $retval;
        } else {
            $result = $this->updateCookieCartNum($cart_id, $num);
            return $result;
        }
    }

    /**
     * 购物车项目删除(non-PHPdoc)
     *
     * @see \data\api\IGoods::cartDelete()
     */
    public function cartDelete($cart_id_array)
    {
        if ($this->uid > 0) {
            $cart = new NsCartModel();
            $retval = $cart->destroy($cart_id_array);
            return $retval;
        } else {
            $result = $this->deleteCookieCart($cart_id_array);
            return $result;
        }
    }

    /**
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::getGroupGoodsList()
     */
    public function getGroupGoodsList($goods_group_id, $condition = '', $num = 0, $order = '')
    {
        $goods_list = array();
        $goods = new NsGoodsModel();
        $condition['state'] = 1;
        $list = $goods->getQuery($condition, '*', $order);
        foreach ($list as $k => $v) {
            $picture = new AlbumPictureModel();
            $picture_info = $picture->get($v['picture']);
            $v['picture_info'] = $picture_info;
            $group_id_array = explode(',', $v['group_id_array']);
            if (in_array($goods_group_id, $group_id_array) || $goods_group_id == 0) {
                $goods_list[] = $v;
            }
        }
        foreach ($goods_list as $k => $v) {
            if (! empty($this->uid)) {
                $member = new Member();
                $goods_list[$k]['is_favorite'] = $member->getIsMemberFavorites($this->uid, $v['goods_id'], 'goods');
            } else {
                $goods_list[$k]['is_favorite'] = 0;
            }
            
            $goods_sku = new NsGoodsSkuModel();
            // 获取sku列表
            $sku_list = $goods_sku->where([
                'goods_id' => $v['goods_id']
            ])->select();
            $goods_list[$k]['sku_list'] = $sku_list;
            
            // 查询课程单品活动信息
            $goods_preference = new GoodsPreference();
            $goods_promotion_info = $goods_preference->getGoodsPromote($v['goods_id']);
            $goods_list[$k]['promotion_info'] = $goods_promotion_info;
        }
        if ($num == 0) {
            return $goods_list;
        } else {
            $count_list = count($goods_list);
            if ($count_list > $num) {
                return array_slice($goods_list, 0, $num);
            } else {
                return $goods_list;
            }
        }
    }

    /**
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::getGroupGoodsList()
     */
    public function getGroupGoodsListForApp($page_index, $page_size, $group_id, $fields = "*", $order = 'sort asc,create_time desc')
    {
        $res = array();
        $goods_list = array();
        $goods = new NsGoodsModel();
        $condition = array();
        $condition['state'] = 1;
        $condition['group_id_array'] = [
            'like',
            '%' . $group_id . '%'
        ];
        $list = $goods->pageQuery($page_index, $page_size, $condition, $order, $fields);
        if (! empty($list['data'])) {
            $picture = new AlbumPictureModel();
            foreach ($list['data'] as $k => $v) {
                $picture_info = $picture->getInfo([
                    "pic_id" => $v['picture']
                ], "pic_cover");
                
                if (strpos($picture_info['pic_cover'], "http") === false) {
                    $list['data'][$k]['pic_cover'] = getBaseUrl() . "/" . $picture_info['pic_cover'];
                }
            }
        }
        $res['goods_list'] = $list;
        
        return $res;
    }

    /**
     * 获取限时折扣的课程
     *
     * @param number $page_index            
     * @param number $page_size            
     * @param unknown $condition            
     * @param string $order            
     */
    public function getDiscountGoodsList($page_index = 1, $page_size = 0, $condition = array(), $order = '')
    {
        $goods_discount = new GoodsDiscount();
        $goods_list = $goods_discount->getDiscountGoodsList($page_index, $page_size, $condition, $order);
        return $goods_list;
    }

    /**
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::getGoodsShopid()
     */
    public function getGoodsShopid($goods_id)
    {
        $goods_model = new NsGoodsModel();
        $goods_info = $goods_model->getInfo([
            'goods_id' => $goods_id
        ], 'shop_id');
        return $goods_info['shop_id'];
    }

    /**
     * 查询课程积分兑换(non-PHPdoc)
     *
     * @see \data\api\IGoods::getGoodsPointExchange()
     */
    public function getGoodsPointExchange($goods_id)
    {
        $goods_model = new NsGoodsModel();
        $goods_info = $goods_model->getInfo([
            'goods_id' => $goods_id
        ], 'point_exchange_type,point_exchange');
        if ($goods_info['point_exchange_type'] == 0) {
            return 0;
        } else {
            return $goods_info['point_exchange'];
        }
    }

    /**
     *
     * @ERROR!!!
     *
     * @see \data\api\IGoods::getConsultTypeList()
     */
    public function getConsultTypeList($page_index = 1, $page_size = 0, $condition = '', $order = '')
    {
        $consult_type = new NsConsultTypeModel();
        $list = $consult_type->pageQuery($page_index, $page_size, $condition, $order, '');
        return $list;
    }

    /**
     *
     * @ERROR!!!
     *
     * @see \data\api\IGoods::getConsultList()
     */
    public function getConsultList($page_index = 1, $page_size = 0, $condition = '', $order = '')
    {
        $consult = new NsConsultModel();
        $list = $consult->pageQuery($page_index, $page_size, $condition, $order, '');
        if (! empty($list)) {
            foreach ($list['data'] as $k => $v) {
                $pic_info = $this->getGoodsImg($v['goods_id']);
                $list['data'][$k]['picture_info'] = $pic_info;
            }
        }
        return $list;
    }

    /**
     *
     * @ERROR!!!
     *
     * @see \data\api\IGoods::addConsult()
     */
    public function addConsult($goods_id, $goods_name, $uid, $member_name, $shop_id, $shop_name, $ct_id, $consult_content)
    {
        $consult = new NsConsultModel();
        $data = array(
            'goods_id' => $goods_id,
            'goods_name' => $goods_name,
            'uid' => $uid,
            'member_name' => $member_name,
            'shop_id' => $shop_id,
            'shop_name' => $shop_name,
            'ct_id' => $ct_id,
            'consult_content' => $consult_content,
            'consult_addtime' => time()
        );
        $consult->save($data);
        $data['consult_id'] = $consult->consult_id;
        hook("consultSaveSuccess", $data);
        $res = $consult->consult_id;
        return $res;
    }

    /**
     *
     * @ERROR!!!
     *
     * @see \data\api\IGoods::replyConsult()
     */
    public function replyConsult($consult_id, $consult_reply)
    {
        $consult = new NsConsultModel();
        $data = array(
            'consult_reply' => $consult_reply,
            'consult_reply_time' => time()
        );
        $res = $consult->save($data, [
            'consult_id' => $consult_id
        ]);
        $data['consult_id'] = $consult_id;
        hook("replyConsultSaveSuccess", $data);
        return $res;
    }

    /**
     *
     * @ERROR!!!
     *
     * @see \data\api\IGoods::addConsultType()
     */
    public function addConsultType($ct_name, $ct_introduce, $ct_sort)
    {}

    /**
     *
     * @ERROR!!!
     *
     * @see \data\api\IGoods::updateConsultType()
     */
    public function updateConsultType($ct_id, $ct_name, $ct_introduce, $ct_sort)
    {}

    /**
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::deleteConsult()
     */
    public function deleteConsult($consult_id)
    {
        $consult = new NsConsultModel();
        return $consult->destroy($consult_id);
    }

    /**
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::deleteConsultType()
     */
    public function deleteConsultType($ct_id)
    {}

    /**
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::getConsultDetail()
     */
    public function getConsultDetail($ct_id)
    {}

    /**
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::getGoodsRank()
     */
    public function getGoodsRank($condition)
    {
        $goods = new NsGoodsModel();
        $goods_list = $goods->where($condition)
            ->order(" real_sales desc ")
            ->limit(5)
            ->select();
        return $goods_list;
    }

    /**
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::getConsultCount()
     */
    public function getConsultCount($condition)
    {
        $consult = new NsConsultModel();
        $count = $consult->where($condition)->count();
        return $count;
    }

    /**
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::getGoodsExpressTemplate()
     */
    public function getGoodsExpressTemplate($goods_id, $province_id, $city_id, $district_id)
    {
        $goods_express = new GoodsExpress();
        $retval = $goods_express->getGoodsExpressTemplate($goods_id, $province_id, $city_id, $district_id);
        return $retval;
    }

    public function addGoodsEvaluateReply($id, $replyContent, $replyType)
    {
        $goodsEvaluate = new NsGoodsEvaluateModel();
        if ($replyType == 1) {
            return $goodsEvaluate->save([
                'explain_first' => $replyContent
            ], [
                'id' => $id
            ]);
        } elseif ($replyType == 2) {
            return $goodsEvaluate->save([
                'again_explain' => $replyContent
            ], [
                'id' => $id
            ]);
        }
    }

    public function setEvaluateShowStatu($id)
    {
        $goodsEvaluate = new NsGoodsEvaluateModel();
        $showStatu = $goodsEvaluate->getInfo([
            'id' => $id
        ], 'is_show');
        if ($showStatu['is_show'] == 1) {
            return $goodsEvaluate->save([
                'is_show' => 0
            ], [
                'id' => $id
            ]);
        } elseif ($showStatu['is_show'] == 0) {
            return $goodsEvaluate->save([
                'is_show' => 1
            ], [
                'id' => $id
            ]);
        }
    }

    public function deleteEvaluate($id)
    {
        $goodsEvaluate = new NsGoodsEvaluateModel();
        return $goodsEvaluate->destroy($id);
    }

    /**
     * 回收课程的分页查询
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::getGoodsDeletedList()
     */
    public function getGoodsDeletedList($page_index = 1, $page_size = 0, $condition = '', $order = '')
    {
        // 针对课程分类
        if (! empty($condition['ng.category_id'])) {
            $goods_category = new GoodsCategory();
            $category_list = $goods_category->getCategoryTreeList($condition['ng.category_id']);
            $condition['ng.category_id'] = array(
                'in',
                $category_list
            );
        }
        $goods_view = new NsGoodsDeletedViewModel();
        $list = $goods_view->getGoodsViewList($page_index, $page_size, $condition, $order);
        if (! empty($list['data'])) {
            // 用户针对课程的收藏
            foreach ($list['data'] as $k => $v) {
                if (! empty($this->uid)) {
                    $member = new Member();
                    $list['data'][$k]['is_favorite'] = $member->getIsMemberFavorites($this->uid, $v['goods_id'], 'goods');
                } else {
                    $list['data'][$k]['is_favorite'] = 0;
                }
                // 查询课程单品活动信息
                $goods_preference = new GoodsPreference();
                $goods_promotion_info = $goods_preference->getGoodsPromote($v['goods_id']);
                $list["data"][$k]['promotion_info'] = $goods_promotion_info;
            }
        }
        return $list;
    }

    /**
     * 课程恢复
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::regainGoodsDeleted()
     */
    public function regainGoodsDeleted($goods_ids)
    {
        $goods_array = explode(",", $goods_ids);
        $this->goods->startTrans();
        try {
            foreach ($goods_array as $goods_id) {
                $goods_delete_model = new NsGoodsDeletedModel();
                $goods_delete_obj = $goods_delete_model->getInfo([
                    "goods_id" => $goods_id
                ]);
                $goods_delete_obj = json_decode(json_encode($goods_delete_obj), true);
                $goods_model = new NsGoodsModel();
                $goods_model->save($goods_delete_obj);
                $goods_delete_model->where("goods_id=$goods_id")->delete();
                // sku 恢复
                $goods_sku_delete_model = new NsGoodsSkuDeletedModel();
                $sku_delete_list = $goods_sku_delete_model->getQuery([
                    "goods_id" => $goods_id
                ], "*", "");
                foreach ($sku_delete_list as $sku_obj) {
                    $sku_obj = json_decode(json_encode($sku_obj), true);
                    $sku_model = new NsGoodsSkuModel();
                    $sku_model->save($sku_obj);
                }
                $goods_sku_delete_model->where("goods_id=$goods_id")->delete();
                // sku图片恢复
                $goods_sku_picture_delete = new NsGoodsSkuPictureDeleteModel();
                $goods_sku_picture_delete_list = $goods_sku_picture_delete->getQuery([
                    'goods_id' => $goods_id
                ], "*", "");
                foreach ($goods_sku_picture_delete_list as $goods_sku_picture_list_delete_obj) {
                    $goods_sku_picture = new NsGoodsSkuPictureModel();
                    $goods_sku_picture_list_delete_obj = json_decode(json_encode($goods_sku_picture_list_delete_obj), true);
                    $goods_sku_picture->save($goods_sku_picture_list_delete_obj);
                }
                $goods_sku_picture_delete->where("goods_id=$goods_id")->delete();
            }
            $this->goods->commit();
            return SUCCESS;
        } catch (\Exception $e) {
            $this->goods->rollback();
            return UPDATA_FAIL;
        }
    }

    /**
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::copyGoodsInfo()
     */
    public function copyGoodsInfo($goods_id)
    {
        $goods_detail = $this->getGoodsDetail($goods_id);
        $goods_attribute = $this->getGoodsAttribute($goods_id);
        $goods_attribute_arr = array();
        foreach ($goods_detail['goods_attribute_list'] as $item) {
            $item_arr = array(
                'attr_value_id' => $item['attr_value_id'],
                'attr_value' => $item['attr_value'],
                'attr_value_name' => $item['attr_value_name'],
                'sort' => $item['sort']
            );
            array_push($goods_attribute_arr, $item_arr);
        }
        $skuArray = '';
        foreach ($goods_detail['sku_list'] as $item) {
            if (! empty($item['attr_value_items'])) {
                $skuArray .= $item['attr_value_items'] . '¦' . $item['price'] . "¦" . $item['market_price'] . "¦" . $item['cost_price'] . "¦" . $item['stock'] . "¦" . $item['code'] . '§';
            }
        }
        $skuArray = rtrim($skuArray, '§');
        // sku规格图片
        $goods_sku_picture = new NsGoodsSkuPictureModel();
        $goods_sku_picture_query = $goods_sku_picture->getQuery([
            "goods_id" => $goods_id
        ], "goods_id, shop_id, spec_id, spec_value_id, sku_img_array", '');
        $goods_sku_picture_query_array = array();
        foreach ($goods_sku_picture_query as $k => $v) {
            $goods_sku_picture_query_array[$k]["spec_id"] = $v["spec_id"];
            $goods_sku_picture_query_array[$k]["spec_value_id"] = $v["spec_value_id"];
            $goods_sku_picture_query_array[$k]["img_ids"] = $v["sku_img_array"];
        }
        if (empty($goods_sku_picture_query_array)) {
            $goods_sku_picture_str = "";
        } else {
            $goods_sku_picture_str = json_encode($goods_sku_picture_query_array);
        }
        // 阶梯优惠信息
        $ladder_preference = "";
        $goodsLadderPreferentialList = $this->getGoodsLadderPreferential([
            "goods_id" => $goods_id
        ]);
        foreach ($goodsLadderPreferentialList as $v) {
            $v = $v["quantity"] . ":" . $v["price"];
        }
        $ladder_preference = implode(",", $goodsLadderPreferentialList);
        
        $virtual_goods_service = new VirtualGoods();
        $virtual_goods_type_info = $virtual_goods_service->getVirtualGoodsTypeInfo([
            'relate_goods_id' => $goods_detail['goods_id']
        ]);
        $virtual_goods_type_data = json_encode($virtual_goods_type_info);
        
        $res = $this->addOrEditGoods(0, $goods_detail['goods_name'] . '-副本', $goods_detail['shop_id'], $goods_detail['category_id'], $goods_detail['category_id_1'], $goods_detail['category_id_2'], $goods_detail['category_id_3'], $goods_detail['supplier_id'], $goods_detail['brand_id'], $goods_detail['group_id_array'], $goods_detail['goods_type'], $goods_detail['market_price'], $goods_detail['price'], $goods_detail['cost_price'], $goods_detail['point_exchange_type'], $goods_detail['point_exchange'], $goods_detail['give_point'], $goods_detail['is_member_discount'], $goods_detail['shipping_fee'], $goods_detail['shipping_fee_id'], $goods_detail['stock'], $goods_detail['max_buy'], $goods_detail['min_buy'], $goods_detail['min_stock_alarm'], $goods_detail['clicks'], $goods_detail['sales'], $goods_detail['collects'], $goods_detail['star'], $goods_detail['evaluates'], $goods_detail['shares'], $goods_detail['province_id'], $goods_detail['city_id'], $goods_detail['picture'], $goods_detail['keywords'], $goods_detail['introduction'], $goods_detail['description'], '', $goods_detail['code'], $goods_detail['is_stock_visible'], $goods_detail['is_hot'], $goods_detail['is_recommend'], $goods_detail['is_new'], $goods_detail['sort'], $goods_detail['img_id_array'], $skuArray, 0, $goods_detail['sku_img_array'], $goods_detail['goods_attribute_id'], json_encode($goods_attribute_arr), $goods_detail['goods_spec_format'], $goods_detail['goods_weight'], $goods_detail['goods_volume'], $goods_detail['shipping_fee_type'], $goods_detail['extend_category_id'], $goods_sku_picture_str, $virtual_goods_type_data, $goods_detail['production_date'], $goods_detail['shelf_life'], $ladder_preference, $goods_detail['goods_video_address'], $goods_detail['pc_custom_template'], $goods_detail['wap_custom_template'], $goods_detail['max_use_point'], $goods_detail['is_open_presell'], $goods_detail['presell_delivery_type'], $goods_detail['presell_price'], $goods_detail['presell_time'], $goods_detail['presell_day'], $goods_detail['goods_unit']);
        return $res;
    }

    /**
     * 删除回收站课程
     *
     * @param unknown $goods_id            
     * @return string
     */
    public function deleteRecycleGoods($goods_id)
    {
        $goods_delete = new NsGoodsDeletedModel();
        $goods_delete->startTrans();
        try {
            $res = $goods_delete->where("goods_id in ($goods_id) and shop_id=$this->instance_id ")->delete();
            if ($res > 0) {
                $goods_id_array = explode(',', $goods_id);
                $goods_sku_model = new NsGoodsSkuDeletedModel();
                $goods_sku_picture_delete = new NsGoodsSkuPictureDeleteModel();
                foreach ($goods_id_array as $k => $v) {
                    // 删除课程sku
                    $goods_sku_model->where("goods_id = $v")->delete();
                    // 删除
                    $goods_sku_picture_delete->where("goods_id = $v")->delete();
                }
            }
            $goods_delete->commit();
            if ($res > 0) {
                return SUCCESS;
            } else {
                return DELETE_FAIL;
            }
        } catch (\Exception $e) {
            $goods_delete->rollback();
            return DELETE_FAIL;
        }
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::deleteCookieCart()删除cookie购物车
     */
    private function deleteCookieCart($cart_id_array)
    {
        // TODO Auto-generated method stub
        // 获取删除条件拼装
        $cart_id_array = trim($cart_id_array);
        if (empty($cart_id_array) && $cart_id_array != 0) {
            return 0;
        }
        // 获取购物车
        $cart_goods_list = cookie('cart_array');
        if (empty($cart_goods_list)) {
            $cart_goods_list = array();
        } else {
            $cart_goods_list = json_decode($cart_goods_list, true);
        }
        foreach ($cart_goods_list as $k => $v) {
            if (strpos((string) $cart_id_array, (string) $v["cart_id"]) !== false) {
                unset($cart_goods_list[$k]);
            }
        }
        if (empty($cart_goods_list)) {
            cookie('cart_array', null);
            return 1;
        } else {
            sort($cart_goods_list);
            try {
                cookie('cart_array', json_encode($cart_goods_list), 3600);
                return 1;
            } catch (\Exception $e) {
                return 0;
            }
        }
    }

    /**
     * 修改cookie购物车的数量
     *
     * @param unknown $cart_id            
     * @param unknown $num            
     * @return number
     */
    private function updateCookieCartNum($cart_id, $num)
    {
        // 获取购物车
        $cart_goods_list = cookie('cart_array');
        if (empty($cart_goods_list)) {
            $cart_goods_list = array();
        } else {
            $cart_goods_list = json_decode($cart_goods_list, true);
        }
        foreach ($cart_goods_list as $k => $v) {
            if ($v["cart_id"] == $cart_id) {
                $cart_goods_list[$k]["num"] = $num;
            }
        }
        sort($cart_goods_list);
        try {
            cookie('cart_array', json_encode($cart_goods_list), 3600);
            return 1;
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::syncUserCart()
     */
    public function syncUserCart($uid)
    {
        // TODO Auto-generated method stub
        $cart = new NsCartModel();
        $cart_query = $cart->getQuery([
            "buyer_id" => $uid
        ], '*', '');
        // 获取购物车
        $cart_goods_list = cookie('cart_array');
        if (empty($cart_goods_list)) {
            $cart_goods_list = array();
        } else {
            $cart_goods_list = json_decode($cart_goods_list, true);
        }
        $goodsmodel = new NsGoodsModel();
        $web_site = new WebSite();
        $goods_sku = new NsGoodsSkuModel();
        
        $web_info = $web_site->getWebSiteInfo();
        // 遍历cookie购物车
        if (! empty($cart_goods_list)) {
            foreach ($cart_goods_list as $k => $v) {
                // 课程信息
                $goods_info = $goodsmodel->getInfo([
                    'goods_id' => $v['goods_id']
                ], 'picture, goods_name, price');
                // sku信息
                $sku_info = $goods_sku->getInfo([
                    'sku_id' => $v['sku_id']
                ], 'price, sku_name, promote_price');
                if (empty($goods_info)) {
                    break;
                }
                if (empty($sku_info)) {
                    break;
                }
                // 查看用户会员价
                $goods_preference = new GoodsPreference();
                if (! empty($this->uid)) {
                    $member_discount = $goods_preference->getMemberLevelDiscount($uid);
                } else {
                    $member_discount = 1;
                }
                $member_price = $member_discount * $sku_info['price'];
                if ($member_price > $sku_info["promote_price"]) {
                    $price = $sku_info["promote_price"];
                } else {
                    $price = $member_price;
                }
                // 判断此用户有无购物车
                if (empty($cart_query)) {
                    // 获取课程sku信息
                    $this->addCart($uid, $this->instance_id, $web_info['title'], $v["goods_id"], $goods_info["goods_name"], $v["sku_id"], $sku_info["sku_name"], $price, $v["num"], $goods_info["picture"], 0);
                } else {
                    $is_have = true;
                    foreach ($cart_query as $t => $m) {
                        if ($m["sku_id"] == $v["sku_id"] && $m["goods_id"] == $v["goods_id"]) {
                            $is_have = false;
                            $num = $m["num"] + $v["num"];
                            $this->cartAdjustNum($m["cart_id"], $num);
                            break;
                        }
                    }
                    if ($is_have) {
                        $this->addCart($uid, $this->instance_id, $web_info['title'], $v["goods_id"], $goods_info["goods_name"], $v["sku_id"], $sku_info["sku_name"], $price, $v["num"], $goods_info["picture"], 0);
                    }
                }
            }
        }
        cookie('cart_array', null);
    }

    /**
     * 更改课程排序
     *
     * @param unknown $goods_id            
     * @param unknown $sort            
     * @return boolean
     */
    public function updateGoodsSort($goods_id, $sort)
    {
        $goods = new NsGoodsModel();
        return $goods->save([
            'sort' => $sort
        ], [
            'goods_id' => $goods_id
        ]);
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::addGoodsSkuPicture()
     */
    public function addGoodsSkuPicture($shop_id, $goods_id, $spec_id, $spec_value_id, $sku_img_array)
    {
        // TODO Auto-generated method stub
        $goods_sku_picture = new NsGoodsSkuPictureModel();
        $data = array(
            "shop_id" => $shop_id,
            "goods_id" => $goods_id,
            "spec_id" => $spec_id,
            "spec_value_id" => $spec_value_id,
            "sku_img_array" => $sku_img_array,
            "create_time" => time(),
            "modify_time" => time()
        );
        $retval = $goods_sku_picture->save($data);
        return $retval;
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::deleteGoodsSkuPicture()
     */
    public function deleteGoodsSkuPicture($condition)
    {
        // TODO Auto-generated method stub
        $goods_sku_picture = new NsGoodsSkuPictureModel();
        $retval = $goods_sku_picture->destroy($condition);
        return $retval;
    }

    /**
     * 获取随机课程
     *
     * @ERROR!!!
     *
     * @see \data\api\IGoods::getRandGoodsList()
     */
    public function getRandGoodsList()
    {
        $result = $this->goods->getQuery([
            'state' => 1
        ], 'goods_id', '');
        $res = array_rand($result, 12);
        $goods_id_list = array();
        foreach ($res as $v) {
            $goods_id_list[] = $result[$v];
        }
        $goodsList = array();
        foreach ($goods_id_list as $g) {
            $goodsList[] = $this->getGoodsDetail($g['goods_id']);
        }
        return $goodsList;
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::getGoodsSkuQuery()
     */
    public function getGoodsSkuQuery($condition)
    {
        // TODO Auto-generated method stub
        $goods_sku_model = new NsGoodsSkuModel();
        $goods_query = $goods_sku_model->getQuery($condition, "goods_id", "");
        return $goods_query;
    }

    /**
     * 获取课程优惠劵
     */
    public function getGoodsCoupon($goods_id, $uid, $is_bespoke=0)
    {
        $coupon_goods = new NsCouponGoodsModel();
        $coupon_type = new NsCouponTypeModel();
        $coupon = new NsCouponModel();
        // 通过课程id获取到优惠劵类型
        $coupon_goods_type_id_list = $coupon_goods->getQuery([
            'goods_id' => ['in',$goods_id]
        ], 'coupon_type_id,goods_id', '');
        // 去除掉未开始的和已结束的
        foreach ($coupon_goods_type_id_list as $k => $v) {
            $res = $coupon_type->getInfo([
                'coupon_type_id' => $v['coupon_type_id'],
                'is_bespoke' => $is_bespoke
            ], "start_time,end_time");
            if ($res['start_time'] > time() || time() > $res['end_time']) {
                unset($coupon_goods_type_id_list[$k]);
            }
        }

        // 获取全课程优惠劵
        $conditions = array(
            'start_time' => array(
                'ELT',
                time()
            ),
            'end_time' => array(
                'EGT',
                time()
            ),
            'range_type' => 1,
            'is_show' => 1,
        );
        $coupon_type_id_list = $coupon_type->getQuery($conditions, 'coupon_type_id', '');
        foreach ($coupon_type_id_list as $v) {
            array_push($coupon_goods_type_id_list, $v);
        }
        $coupon_list = array();
        foreach ($coupon_goods_type_id_list as $v) {
            // 已领取，已使用的数目
            $already_received = $coupon->getCount([
                'coupon_type_id' => $v['coupon_type_id'],
                "state" => [
                    'neq',
                    0
                ]
            ]);
            $condition = array(
                'start_time' => array(
                    'ELT',
                    time()
                ),
                'end_time' => array(
                    'EGT',
                    time()
                ),
                'coupon_type_id' => $v['coupon_type_id'],
                'count' => array(
                    'GT',
                    $already_received
                )
            );
            $coupon_detial = $coupon_type->getInfo($condition, 'money,max_fetch,at_least,coupon_type_id,start_time,end_time,max_fetch');
            if (! empty($coupon_detial)) {
                $receive_quantity = $coupon->getCount([
                    "coupon_type_id" => $coupon_detial['coupon_type_id'],
                    "uid" => $uid
                ]);
                // if($coupon_detial['max_fetch'] == 0 || $coupon_detial['max_fetch']> $receive_quantity){
                $coupon_detial['receive_quantity'] = $receive_quantity;
                $coupon_list[] = $coupon_detial;
                // }
            }
        }
        return $coupon_list;
    }
    
    /**
     * 设置点赞送积分
     */
    public function setGoodsSpotFabulous($shop_id, $uid, $goods_id)
    {
        $click_goods = new NsClickFabulousModel();
        // 点赞成功送积分
        $rewardRule = new PromoteRewardRule();
        // 查询点赞赠送积分数量，然后叠加
        $info = $rewardRule->getRewardRuleDetail($shop_id);
        $data = array(
            'shop_id' => $shop_id,
            'uid' => $uid,
            'goods_id' => $goods_id,
            'status' => 1,
            'number' => $info['click_point'],
            'create_time' => time()
        );
        $retval = $click_goods->save($data);
        if ($retval > 0) {
            $res = $rewardRule->addMemberPointData($shop_id, $uid, $info['click_point'], 19, '点赞赠送积分');
        }
        return $retval;
    }

    /**
     * 查询点赞状态
     *
     * @param unknown $shop_id            
     * @param unknown $uid            
     * @param unknown $goods_id            
     */
    public function getGoodsSpotFabulous($shop_id, $uid, $goods_id)
    {
        $click_goods = new NsClickFabulousModel();
        $start_time = mktime(0, 0, 0, date('m'), date('d'), date('Y'));
        $end_time = mktime(0, 0, 0, date('m'), date('d') + 1, date('Y')) - 1;
        $condition = array(
            'shop_id' => $shop_id,
            'uid' => $uid,
            'goods_id' => $goods_id,
            'create_time' => array(
                'between',
                [
                    $start_time,
                    $end_time
                ]
            )
        );
        
        $retval = $click_goods->getInfo($condition);
        return $retval;
    }

    /**
     * 修改课程名称或促销语
     */
    public function updateGoodsNameOrIntroduction($goods_id, $up_type, $up_content)
    {
        $condition = array(
            "goods_id" => $goods_id,
            "shop_id" => $this->instance_id
        );
        if ($up_type == "goods_name") {
            return $this->goods->save([
                "goods_name" => $up_content
            ], $condition);
        } elseif ($up_type == "introduction") {
            return $this->goods->save([
                "introduction" => $up_content
            ], $condition);
        }
    }

    /**
     * 修改课程属性表属性排序
     *
     * @ERROR!!!
     *
     * @see \data\api\IGoods::updateGoodsAttributeSort()
     */
    public function updateGoodsAttributeSort($attr_value_id, $sort, $shop_id)
    {
        $goods_attribute = new NsGoodsAttributeModel();
        return $goods_attribute->save([
            "sort" => $sort
        ], [
            "attr_value_id" => $attr_value_id,
            "shop_id" => $shop_id
        ]);
    }

    /**
     * 查询当前用户所购买的课程限购，是否能够继续购买
     * 1.查询当前课程是否限购
     * 2.如果该课程限购，则查询当前用户的订单项表中是否有该课程的记录
     *
     * @param 课程id $goods_id            
     * @param 店铺id $shop_id            
     * @return int，1：允许购买，0：不允许购买
     */
    function getGoodsPurchaseRestrictionForCurrentUser($goods_id, $num = 0, $flag = "")
    {
        $res = array(
            "code" => 1,
            "message" => "允许购买",
            "value" => 0
        );
        $ns_goods_model = new NsGoodsModel();
        $max_buy = $ns_goods_model->getInfo([
            "goods_id" => $goods_id,
            "shop_id" => $this->instance_id
        ], 'max_buy');
        
        $result = $num; // 用户购买的数量 + 购物车中的数量 + 订单交易数量不能超过课程的限购
                        
        // 检测该课程是否有限购
        if (! empty($max_buy)) {
            if ($max_buy['max_buy'] > 0) {
                
                // 如果当前是订单验证，不需要查询购物车
                if ($flag != "order") {
                    
                    // 检测购物车中是否存在该课程
                    $cart_list = $this->getCart($this->uid);
                    if (! empty($cart_list)) {
                        foreach ($cart_list as $k => $v) {
                            if ($v['goods_id'] == $goods_id) {
                                $result += $v['num'];
                            }
                        }
                    }
                }
                if (! empty($this->uid)) {
                    
                    // 用户可能分开进行购买，统计当前用户购买了多少件该课程
                    $ns_order_goods_model = new NsOrderGoodsModel();
                    $order_goods_list = $ns_order_goods_model->getQuery([
                        "goods_id" => $goods_id,
                        "shop_id" => $this->instance_id,
                        "buyer_id" => $this->uid
                    ], "order_id,num", "");
                    if (! empty($order_goods_list)) {
                        
                        $ns_order_model = new NsOrderModel();
                        foreach ($order_goods_list as $k => $v) {
                            
                            // 查询订单记录，排除已关闭的订单
                            $count = $ns_order_model->getCount([
                                'order_id' => $v['order_id'],
                                "order_status" => [
                                    "neq",
                                    5
                                ]
                            ]);
                            if ($count > 0) {
                                $result += $v['num'];
                            }
                        }
                    }
                }
                if ($result > $max_buy['max_buy']) {
                    
                    $res['code'] = 0;
                    $res['message'] = "该课程每人限购" . $max_buy['max_buy'] . "件";
                    $res['value'] = $result - $max_buy['max_buy']; // 还能购买的课程数量
                }
            }
        }
        
        return $res;
    }

    /**
     * 添加营销活动时获取课程列表
     *
     * @param unknown $page_index            
     * @param unknown $page_size            
     * @param unknown $condition            
     * @param unknown $order            
     * @param unknown $field            
     * @return number[]|unknown[]
     */
    public function getSelectGoodsList($page_index, $page_size, $condition, $order, $field)
    {
        $ns_goods = new NsGoodsModel();
        $list = $ns_goods->pageQuery($page_index, $page_size, $condition, $order, $field);
        return $list;
    }

    /**
     * 获取课程阶梯优惠
     *
     * @param unknown $condition            
     * @return unknown
     */
    public function getGoodsLadderPreferential($condition, $order = "", $filed = "*")
    {
        $nsGoodsLadderPreferential = new NsGoodsLadderPreferentialModel();
        $list = $nsGoodsLadderPreferential->pageQuery(1, 0, $condition, $order, $filed);
        return $list["data"];
    }

    /**
     * 获取购买数量满足条件的阶梯优惠信息
     *
     * @param unknown $goods_id            
     * @param unknown $num            
     */
    public function getGoodsLadderPreferentialInfo($goods_id, $num, $goods_price)
    {
        $nsGoodsLadderPreferential = new NsGoodsLadderPreferentialModel();
        $condition["goods_id"] = $goods_id;
        $condition["quantity"] = array(
            "ELT",
            $num
        );
        $res = $nsGoodsLadderPreferential->pageQuery(1, 1, $condition, "quantity desc", "*");
        if ($res["total_count"] > 0) {
            $goods_price -= $res["data"][0]["price"];
        }
        $goods_price = $goods_price < 0 ? 0 : $goods_price;
        return $goods_price;
    }
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::getGoodsBrowseList()
     */
    public function getGoodsBrowseList($page_index, $page_size, $condition, $order, $field = "*")
    {
        // TODO Auto-generated method stub
        $goods_browse = new NsGoodsBrowseModel();
        $goods_browse_list = $goods_browse->pageQuery($page_index, $page_size, $condition, $order, $field);
        $category_list = array();
        if (! empty($goods_browse_list)) {
            foreach ($goods_browse_list["data"] as $k => $v) {
                $goods_info = $this->goods->getInfo([
                    "goods_id" => $v["goods_id"]
                ], "category_id, category_id_1, goods_name, promotion_type, promotion_price, shop_id, price, picture, clicks, point_exchange_type, point_exchange");
                
                $ablum_picture = new AlbumPictureModel();
                $picture_info = $ablum_picture->getInfo([
                    "pic_id" => $goods_info["picture"]
                ]);
                $goods_info["picture_info"] = $picture_info;
                $goods_category = new NsGoodsCategoryModel();
                $category_info = $goods_category->getInfo([
                    "category_id" => $v["category_id"]
                ], "category_name, short_name, category_id");
                // 判断数组是否存在(拼装分类列表)
                if (! empty($category_info)) {
                    if (! in_array($category_info, $category_list)) {
                        $category_list[] = $category_info;
                    }
                }
                $goods_browse_list["data"][$k]["goods_info"] = $goods_info;
                $goods_browse_list["data"][$k]["category"] = $category_info;
            }
        }
        $goods_browse_list["category_list"] = $category_list;
        
        return $goods_browse_list;
    }
    
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::addGoodsBrowse()
     */
    public function addGoodsBrowse($goods_id, $uid)
    {
        // TODO Auto-generated method stub
        $goods_browse = new NsGoodsBrowseModel();
        try {
            // 判断原足迹中是否有这个课程
            $condition = array(
                "goods_id" => $goods_id,
                "uid" => $uid
            );
            $count = $goods_browse->getCount($condition);
            if ($count > 0) {
                $goods_browse->destroy($condition);
            }
            $goods_model = new NsGoodsModel();
            $goods_info = $goods_model->getInfo([
                "goods_id" => $goods_id
            ], "category_id");
            $data = array(
                "goods_id" => $goods_id,
                "uid" => $uid,
                "create_time" => time(),
                "category_id" => $goods_info["category_id"]
            );
            $goods_browse->save($data);
            $goods_browse->commit();
            return $goods_browse->browse_id;
        } catch (\Exception $e) {
            $goods_browse->rollback();
            return 0;
        }
    }
    /*
     * (non-PHPdoc)
     * @see \data\api\IGoods::deleteGoodsBrowse()
     */
    public function deleteGoodsBrowse($condition)
    {
        // TODO Auto-generated method stub
        $goods_browse = new NsGoodsBrowseModel();
        $retval = $goods_browse->destroy($condition);
        return $retval;
    }

    /**
     * 根据条件、指定数量查询课程
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::getRecommendGoodsQuery()
     */
    public function getGoodsQueryLimit($condition, $field, $page_size = PAGESIZE, $order = "ng.sort asc")
    {
        $goods_model = new NsGoodsModel();
        $list = $goods_model->alias("ng")
            ->join('sys_album_picture ng_sap', 'ng_sap.pic_id = ng.picture', 'left')
            ->field($field)
            ->where($condition)
            ->order($order)
            ->limit("0,$page_size")
            ->select();
        return $list;
    }

    /**
     * 课程表视图，不关联任何表
     *
     * @param unknown $condition            
     * @param unknown $field            
     * @param unknown $order            
     */
    public function getGoodsViewQueryField($condition, $field, $order)
    {
        $goods_model = new NsGoodsModel();
        $viewObj = $goods_model->alias('ng')->field($field);
        $list = $viewObj->where($condition)
            ->order($order)
            ->select();
        return $list;
    }

    /**
     * 获取课程查询数量，分页用
     *
     * @param unknown $condition            
     * @return unknown
     */
    public function getGoodsQueryCount($condition, $where_sql = "")
    {
        $goods_model = new NsGoodsModel();
        $viewObj = $goods_model->alias('ng');
        if (! empty($where_sql)) {
            $count = $goods_model->viewCountNew($viewObj, $condition, $where_sql);
        } else {
            $count = $goods_model->viewCount($viewObj, $condition);
        }
        return $count;
    }

    /**
     * 后台课程列表
     *
     * @param number $page_index            
     * @param number $page_size            
     * @param string $condition            
     * @param string $order            
     * @return unknown|\think\cache\mixed
     */
    public function getBackStageGoodsList($page_index = 1, $page_size = 0, $condition = '', $order = 'ng.sort asc')
    {
        // $json = json_encode($condition);
        // $list_cache = Cache::tag("goods_service")->get("get_back_stage_goods_list" . $json . $page_index);
        // if (empty($list_cache)) {
        $goods_model = new NsGoodsModel();
        // 针对课程分类
        if (! empty($condition['ng.category_id'])) {
            $goods_category = new GoodsCategory();
            
            // 获取当前课程分类的子分类
            $category_list = $goods_category->getCategoryTreeList($condition['ng.category_id']);
            
            unset($condition['ng.category_id']);
            $query_goods_ids = "";
            $goods_list = $this->getGoodsViewQueryField($condition, "ng.goods_id", "");
            if (! empty($goods_list) && count($goods_list) > 0) {
                foreach ($goods_list as $goods_obj) {
                    if ($query_goods_ids === "") {
                        $query_goods_ids = $goods_obj["goods_id"];
                    } else {
                        $query_goods_ids = $query_goods_ids . "," . $goods_obj["goods_id"];
                    }
                }
                $extend_query = "";
                $category_str = explode(",", $category_list);
                foreach ($category_str as $category_id) {
                    if ($extend_query === "") {
                        $extend_query = " FIND_IN_SET( " . $category_id . ",ng.extend_category_id) ";
                    } else {
                        $extend_query = $extend_query . " or FIND_IN_SET( " . $category_id . ",ng.extend_category_id) ";
                    }
                }
                $condition = " ng.goods_id in (" . $query_goods_ids . ") and ( ng.category_id in (" . $category_list . ") or " . $extend_query . ")";
            }
        }
        
        $viewObj = $goods_model->alias("ng")
            ->join('sys_album_picture ng_sap', 'ng_sap.pic_id = ng.picture', 'left')
            ->join('ns_goods_category ng_cate1', 'ng_cate1.category_id = ng.category_id_1', 'left')
            ->join('ns_goods_category ng_cate2', 'ng_cate2.category_id = ng.category_id_2', 'left')
            ->join('ns_goods_category ng_cate3', 'ng_cate3.category_id = ng.category_id_3', 'left')
            ->field("ng.category_id_1,ng.category_id_2,ng.category_id_3,ng.goods_id,ng.goods_name,ng.promotion_price,ng.market_price,ng.goods_type,ng.stock,ng.introduction,ng.max_buy,ng.state,ng.is_hot,ng.is_recommend,ng.is_new,ng.sales,ng_sap.pic_cover_micro,ng.code,ng.create_time,ng.QRcode,ng.price,ng.real_sales,ng.sort,ng.group_id_array,ng.goods_kind,ng.allow_delete,ng_cate1.category_name as category_name1,ng_cate2.category_name as category_name2,ng_cate3.category_name as category_name3");
        $queryList = $goods_model->viewPageQuery($viewObj, $page_index, $page_size, $condition, $order);
        $queryCount = $this->getGoodsQueryCount($condition);
        $list = $goods_model->setReturnList($queryList, $queryCount, $page_size);
        
        // Cache::tag("goods_service")->set("get_back_stage_goods_list" . $json . $page_index, $list);
        return $list;
        // } else {
        // return $list_cache;
        // }
    }

    /**
     * 优化过后的课程列表
     *
     * @param number $page_index            
     * @param number $page_size            
     * @param string $condition            
     * @param string $order            
     * @return unknown|\think\cache\mixed
     */
    public function getGoodsListNew($page_index = 1, $page_size = 0, $condition = '', $order = 'ng.sort asc')
    {
        $json = json_encode($condition);
        $goods_model = new NsGoodsModel();
        $where_sql = "";
        // 针对课程分类
        if (! empty($condition['ng.category_id'])) {
            $goods_category = new GoodsCategory();
            $select_category_id = $condition['ng.category_id'];
            unset($condition['ng.category_id']);
            $category_model = new NsGoodsCategoryModel();
            $select_category_obj = $category_model->getInfo([
                "category_id" => $select_category_id
            ], "level");
            $select_level = $select_category_obj["level"];
            if ($select_level == 1) {
                $where_sql = "(ng.category_id_1=$select_category_id or FIND_IN_SET( " . $select_category_id . ",ng.extend_category_id_1))";
            } elseif ($select_level == 2) {
                $where_sql = "(ng.category_id_2=$select_category_id or FIND_IN_SET( " . $select_category_id . ",ng.extend_category_id_2))";
            } elseif ($select_level == 3) {
                $where_sql = "(ng.category_id_3=$select_category_id or FIND_IN_SET( " . $select_category_id . ",ng.extend_category_id_3))";
            }
        }
        $viewObj = $goods_model->alias("ng")
            ->join('sys_album_picture ng_sap', 'ng_sap.pic_id = ng.picture', 'left')
            ->field("ng.goods_kind,ng.goods_id,ng.goods_name,ng_sap.pic_cover_mid,ng.promotion_price,ng.market_price,ng.goods_type,ng.stock,ng_sap.pic_id,ng.max_buy,ng.state,ng.is_hot,ng.is_recommend,ng.is_new,ng.sales,ng_sap.pic_cover_small,ng.group_id_array,ng.shipping_fee,ng.point_exchange_type,ng.point_exchange");
        $queryList = $goods_model->viewPageQueryNew($viewObj, $page_index, $page_size, $condition, $where_sql, $order);
        $queryCount = $this->getGoodsQueryCount($condition, $where_sql);
        $list = $goods_model->setReturnList($queryList, $queryCount, $page_size);
        $goods_sku = new NsGoodsSkuModel();
        $goods_preference = new GoodsPreference();
        // 用户针对课程的收藏
        foreach ($list['data'] as $k => $v) {
            if (! empty($this->uid)) {
                $member = new Member();
                $list['data'][$k]['is_favorite'] = $member->getIsMemberFavorites($this->uid, $v['goods_id'], 'goods');
            } else {
                $list['data'][$k]['is_favorite'] = 0;
            }
            // 查询课程单品活动信息
            // $goods_promotion_info = $goods_preference->getGoodsPromote($v['goods_id']);
            // $list["data"][$k]['promotion_info'] = $goods_promotion_info;
            
            // // 获取sku列表
            // $sku_list = $goods_sku->where([
            // 'goods_id' => $v['goods_id']
            // ])
            // ->field("attr_value_items,stock,promote_price,price,sku_id,sku_name")
            // ->select();
            
            $list['data'][$k]['sku_list'] = array();
            if($v['point_exchange_type'] == 0 || $v['point_exchange_type'] == 2){
                $list['data'][$k]['display_price'] = '￥'.$v["promotion_price"];
            }else{
                if($v['point_exchange_type'] == 1 && $v["promotion_price"] > 0){
                    $list['data'][$k]['display_price'] = '￥'.$v["promotion_price"].'+'.$v["point_exchange"].'积分';
                }else{
                    $list['data'][$k]['display_price'] = $v["point_exchange"].'积分';
                }
            }
            // $list['data'][$k]['sku_list'] = $sku_list;
        }
        return $list;
    }

    /**
     * 修改课程点击量
     * (non-PHPdoc)
     *
     * @see \data\api\IGoods::updateGoodsClicks()
     */
    public function updateGoodsClicks($goods_id)
    {
        $res = 0;
        $model = new NsGoodsModel();
        $info = $model->getInfo([
            'goods_id' => $goods_id
        ], "clicks");
        if (! empty($info)) {
            $clicks = 0;
            if (! empty($info['clicks'])) {
                $clicks = $info['clicks'];
            }
            $clicks ++;
            $res = $model->save([
                'clicks' => $clicks
            ], [
                'goods_id' => $goods_id
            ]);
        }
        return $res;
    }

    /**
     * 通过课程id获取课程sku列表
     *
     * @param unknown $goods_id            
     */
    public function getGoodsSkuListByGoodsId($goods_id)
    {
        $goods_sku = new NsGoodsSkuModel();
        $goods_preference = new GoodsPreference();
        
        $goods_promotion_info = $goods_preference->getGoodsPromote($goods_id);
        
        $sku_list = $goods_sku->where([
            'goods_id' => $goods_id
        ])
            ->field("attr_value_items,stock,promote_price,price,sku_id,sku_name")
            ->select();
        
        if (! empty($sku_list)) {
            foreach ($sku_list as $k => $v) {
                // 判断该课程目前是否参与活动 参与的话sku价格取 促销价 否则取原价
                $sku_list[$k]["price"] = empty($goods_promotion_info) ? $v['price'] : $v['promote_price'];
            }
        }
        return $sku_list;
    }

    /**
     * 课程批量处理
     *
     * @param unknown $info            
     */
    public function batchProcessingGoods($info)
    {
        if (! empty($info['goods_ids'])) {
            $goods_model = new NsGoodsModel(); // 课程主表
            $goods_sku_model = new NsGoodsSkuModel(); // 课程sku表
                                                      // 开启事物
            $goods_model->startTrans();
            try {
                $goods_id_array = explode(',', $info['goods_ids']);
                if (count($goods_id_array) > 0) {
                    foreach ($goods_id_array as $v) {
                        $goods_data = array(); // 课程修改项
                        if ($info['brand_id'] != 0) {
                            $goods_data['brand_id'] = $info['brand_id'];
                        }
                        
                        if ($info['catrgory_one'] > 0) {
                            $goods_data['category_id_1'] = $info['catrgory_one'];
                            $goods_data['category_id_2'] = $info['catrgory_two'];
                            $goods_data['category_id_3'] = $info['catrgory_three'];
                            if ($info['catrgory_three'] > 0) {
                                $goods_data['category_id'] = $info['catrgory_three'];
                            } else 
                                if ($info['catrgory_two'] > 0) {
                                    $goods_data['category_id'] = $info['catrgory_two'];
                                } else {
                                    $goods_data['category_id'] = $info['catrgory_one'];
                                }
                        }
                        
                        $condition["goods_id"] = $v;
                        // 课程sku列表
                        $goods_sku_list = $goods_sku_model->getQuery($condition, "*", "");
                        
                        foreach ($goods_sku_list as $goods_sku) {
                            $data = array(); // 课程sku修该项
                            if ($info['price'] != 0) {
                                $price = $goods_sku["price"] + $info['price'];
                                $data['price'] = $price < 0 ? 0 : $price;
                                $data['promote_price'] = $price < 0 ? 0 : $price;
                            }
                            if ($info['market_price'] != 0) {
                                $market_price = $goods_sku["market_price"] + $info['market_price'];
                                $data['market_price'] = $market_price < 0 ? 0 : $market_price;
                            }
                            if ($info['cost_price'] != 0) {
                                $cost_price = $goods_sku["cost_price"] + $info['cost_price'];
                                $data['cost_price'] = $cost_price < 0 ? 0 : $cost_price;
                            }
                            if ($info['stock'] != 0) {
                                $stock = $goods_sku["stock"] + $info['stock'];
                                $data['stock'] = $stock < 0 ? 0 : $stock;
                            }
                            $goods_sku_model = new NsGoodsSkuModel(); // 课程sku表
                            if(count($data) > 0){
                                $goods_sku_model->save($data, [
                                    "sku_id" => $goods_sku['sku_id']
                                ]);
                            }
                        }
                       
                        $goods_data['stock'] = $goods_sku_model->getSum($condition, "stock");
                        $goods_data['promotion_price'] = $goods_sku_model->getMin($condition, "price");
                        $goods_data['price'] = $goods_sku_model->getMin($condition, "price");
                        $goods_data['market_price'] = $goods_sku_model->getMin($condition, "market_price");
                        $goods_data['cost_price'] = $goods_sku_model->getMin($condition, "cost_price");
                        $goods_model = new NsGoodsModel(); // 课程主表
                        if(count($goods_data) > 0){
                            $goods_model->save($goods_data, [
                                "goods_id" => $v
                            ]);
                        }
                    }
                }
                $goods_model->commit();
                return $retval = array(
                    "code" => 1,
                    "message" => '操作成功'
                );
            } catch (\Exception $e) {
                $goods_model->rollback();
                return $retval = array(
                    "code" => 0,
                    "message" => $e->getMessage()
                );
            }
        } else {
            return $retval = array(
                "code" => 0,
                "message" => '请至少选择一件课程'
            );
        }
    }

    /**
     * 获取规格信息
     * 
     * @param unknown $condition            
     * @return unknown
     */
    public function getGoodsSpecInfoQuery($condition)
    {
        $condition_spec = array();
        // TODO Auto-generated method stub
        if ($condition["attr_id"] > 0) {
            $goods_attribute = $this->getAttributeInfo($condition);
            $condition_spec["spec_id"] = array(
                "in",
                $goods_attribute['spec_id_array']
            );
        }
        $condition_spec["is_visible"] = 1;
        $spec_list = $this->getGoodsSpecQuery($condition_spec); // 课程规格
        $list["spec_list"] = $spec_list; // 课程规格集合
        return $list;
    }

    public function deleteSpecValue($condition)
    {
        // 删掉规格下的属性
        $goods_spec_value = new NsGoodsSpecValueModel();
        return $goods_spec_value->destroy($condition);
    }
}