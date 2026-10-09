<?php
declare(strict_types=1);
// Page: ?page=sales — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('view_tables'); $schema=current_schema();
    $orderNo = trim((string)($_GET['order_no'] ?? ''));
    $iccid = trim((string)($_GET['iccid'] ?? ''));
    $order = null; $items = []; $invoices = []; $ordersByIccid = [];
    if ($orderNo !== '') { $order = find_sales_order($schema, $orderNo); $items = sales_order_items_for($schema, $orderNo); $invoices = sales_invoices_for($schema, $orderNo); }
    elseif ($iccid !== '') { $ordersByIccid = find_sales_orders_by_iccid($schema, $iccid); }
    layout_start('Sales & Invoices');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-file-invoice-dollar me-2"></i>Sales Orders & Invoices</h3>
        <p class="text-muted">Look up an order by Order No. or ICCID to see its line items and linked invoice in one place.</p>
        <form method="get" class="row g-2"><input type="hidden" name="page" value="sales">
            <div class="col-md-4"><label>Order No.</label><input class="form-control" name="order_no" value="<?=e($orderNo)?>"></div>
            <div class="col-md-4"><label>ICCID</label><input class="form-control" name="iccid" value="<?=e($iccid)?>"></div>
            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100">Search</button></div>
        </form>
    </div>
    <?php if ($orderNo !== ''): if (!$order): ?>
        <div class="alert alert-warning mt-3">No order found with Order No. "<?=e($orderNo)?>".</div>
    <?php else: ?>
        <div class="row g-3 mt-1">
            <div class="col-lg-4"><div class="cardx"><h3>Order <?=e($order['order_no'])?></h3><div class="table-scroll"><table class="table table-sm mb-0">
                <tr><th>Created</th><td><?=e($order['created_at'])?></td></tr>
                <tr><th>Quantity</th><td><?=e($order['quantity'])?></td></tr>
                <tr><th>Amount payable</th><td><?=e($order['amount_payable'])?></td></tr>
                <tr><th>Amount paid</th><td><?=e($order['amount_paid'])?></td></tr>
                <tr><th>Shipping</th><td><?=e($order['amount_shipping'])?></td></tr>
                <tr><th>Discount</th><td><?=e($order['discount'])?></td></tr>
                <tr><th>ICCID</th><td><?=e($order['iccid'])?></td></tr>
            </table></div></div></div>
            <div class="col-lg-4"><div class="cardx"><h3>Line Items</h3><?php if(!$items):?><p class="text-muted mb-0">No line items.</p><?php else:?><div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>Offer</th><th>Qty</th><th>Fee</th><th>Bundle</th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><?=e($it['offer_id'])?></td><td><?=e($it['quantity'])?></td><td><?=e($it['fee'])?></td><td><?=e($it['bundle_code'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
            <div class="col-lg-4"><div class="cardx"><h3>Invoices</h3><?php if(!$invoices):?><p class="text-muted mb-0">No invoice on file.</p><?php else:?><div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>Invoice No.</th><th>Status</th><th>Created</th></tr></thead><tbody><?php foreach($invoices as $inv):?><tr><td><?=e($inv['invoice_no'])?></td><td><?=e($inv['status'])?></td><td><?=e($inv['created_at'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
        </div>
    <?php endif; elseif ($iccid !== ''): ?>
        <div class="cardx mt-3"><h3>Orders for ICCID <?=e($iccid)?></h3><?php if(!$ordersByIccid):?><p class="text-muted mb-0">No orders found.</p><?php else:?><table class="table table-hover table-sm"><thead><tr><th>Order No.</th><th>Created</th><th>Qty</th><th>Amount Payable</th><th></th></tr></thead><tbody><?php foreach($ordersByIccid as $o):?><tr><td><?=e($o['order_no'])?></td><td><?=e($o['created_at'])?></td><td><?=e($o['quantity'])?></td><td><?=e($o['amount_payable'])?></td><td><a class="btn btn-sm btn-outline-primary" href="?page=sales&order_no=<?=urlencode((string)$o['order_no'])?>">View</a></td></tr><?php endforeach;?></tbody></table><?php endif;?></div>
    <?php endif; layout_end(); exit;
