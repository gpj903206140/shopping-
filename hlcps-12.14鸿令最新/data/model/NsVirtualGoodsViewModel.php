<?php
namespace data\model;

use data\model\BaseModel as BaseModel;
/**
 * 虚拟码课程表
 * @author Administrator
 *
 */
class NsVirtualGoodsViewModel extends BaseModel {
    protected $table = 'ns_virtual_goods';
 /**
     * 获取列表返回数据格式
     * @param unknown $page_index
     * @param unknown $page_size
     * @param unknown $condition
     * @param unknown $order
     * @return unknown
     */
    public function getViewList($page_index, $page_size, $condition, $order){
    
        $queryList = $this->getViewQuery($page_index, $page_size, $condition, $order);
        $queryCount = $this->getViewCount($condition);
        $list = $this->setReturnList($queryList, $queryCount, $page_size);
        return $list;
    }
    /**
     * 获取列表
     * @param unknown $page_index
     * @param unknown $page_size
     * @param unknown $condition
     * @param unknown $order
     * @return \data\model\multitype:number
     */
    public function getViewQuery($page_index, $page_size, $condition, $order)
    {
        //设置查询视图
        $viewObj = $this->alias('nvg')
        ->join('sys_user su','nvg.buyer_id = su.uid','left')
        ->join('ns_goods ng', 'nvg.goods_id = ng.goods_id', 'left')
        ->field('nvg.virtual_goods_id,nvg.virtual_code,nvg.virtual_goods_name,nvg.money,nvg.buyer_id,su.nick_name,nvg.order_no,nvg.validity_period,nvg.start_time,nvg.end_time,nvg.use_number,nvg.confine_use_number,nvg.use_status,nvg.shop_id,nvg.remark,nvg.create_time,nvg.goods_id,ng.goods_name, ng.picture');
        $list = $this->viewPageQuery($viewObj, $page_index, $page_size, $condition, $order);
        return $list;
    }
    /**
     * 获取列表数量
     * @param unknown $condition
     * @return \data\model\unknown
     */
    public function getViewCount($condition)
    {
        $viewObj = $this->alias('nvg')
        ->join('sys_user su','nvg.buyer_id = su.uid','left')
        ->join('ns_goods ng', 'nvg.goods_id = ng.goods_id', 'left')
        ->field('nvg.virtual_goods_id');
        $count = $this->viewCount($viewObj,$condition);
        return $count;
    }
    
}