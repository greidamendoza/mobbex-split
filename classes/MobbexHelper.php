<?php
/**
 * Mobbex.php
 *
 * Main file of the module
 *
 * @author  Mobbex Co <admin@mobbex.com>
 * @version 1.0.0
 * @see     PaymentModuleCore
 */

/**
 * Payment Provider Class
 */

require_once(dirname(__FILE__).'/../../../modules/marketplace/classes/WkMpSellerProduct.php');
require_once(dirname(__FILE__).'/../../../modules/marketplace/classes/WkMpSeller.php');

class MobbexHelper
{
    const MOBBEX_VERSION = '1.3.5';

    const PS_16 = "1.6";
    const PS_17 = "1.7";

    const K_API_KEY = 'MOBBEX_API_KEY';
    const K_ACCESS_TOKEN = 'MOBBEX_ACCESS_TOKEN';
    const K_TEST_MODE = 'MOBBEX_TEST_MODE';

    // THEMES
    const K_THEME = 'MOBBEX_THEME';
    const K_THEME_BACKGROUND = 'MOBBEX_THEME_BACKGROUND';
    const K_THEME_PRIMARY = 'MOBBEX_THEME_PRIMARY';

    const K_THEME_LOGO = 'MOBBEX_THEME_LOGO';

    // RESELLER ID. Will change to Branch ID in the future
    const K_RESELLER_ID = 'MOBBEX_RESELLER_ID';

    const K_DEF_THEME = true;
    const K_DEF_BACKGROUND = '#ECF2F6';
    const K_DEF_PRIMARY = '#6f00ff';

    const K_OS_PENDING = 'MOBBEX_OS_PENDING';
    const K_OS_WAITING = 'MOBBEX_OS_WAITING';
    const K_OS_REJECTED = 'MOBBEX_OS_REJECTED';

    public static function getUrl($path)
    {
        return Tools::getShopDomain(true, true) . __PS_BASE_URI__ . $path;
    }

    public static function getModuleUrl($controller, $action, $path)
    {
        // controller / module / fc
        // controller=notification
        // module=mobbex
        // fc=module
        return MobbexHelper::getUrl('index.php?controller=' . $controller . '&module=mobbex&fc=module&action=' . $action . $path);
    }

    public static function getWebhookUrl($params)
    {
        return Context::getContext()->link->getModuleLink(
            'mobbex',
            'webhook',
            $params,
            true
        );
    }

    public static function getPlatform()
    {
        return array(
            "name" => "prestashop",
            "verison" => MobbexHelper::MOBBEX_VERSION,
            "platform_version" => _PS_VERSION_,
        );
    }

    public static function getHeaders()
    {
        return array(
            'cache-control: no-cache',
            'content-type: application/x-www-form-urlencoded',
            'x-access-token: ' . Configuration::get(MobbexHelper::K_ACCESS_TOKEN),
            'x-api-key: ' . Configuration::get(MobbexHelper::K_API_KEY),
        );
    }

    public static function getOptions()
    {
        $theme = array(
            "type" => Configuration::get(MobbexHelper::K_THEME) ? 'light' : 'dark',
            "header" => [
                "name" => Configuration::get('PS_SHOP_NAME'),
            ],
        );

        $theme_background = Configuration::get(MobbexHelper::K_THEME_BACKGROUND);
        $theme_primary = Configuration::get(MobbexHelper::K_THEME_PRIMARY);
        $theme_logo = Configuration::get(MobbexHelper::K_THEME_LOGO);

        if (isset($theme_background) && $theme_background != '') {
            $theme = array_merge($theme, array(
                "background" => $theme_background,
            ));
        }

        if (isset($theme_primary) && $theme_primary != '') {
            $theme = array_merge($theme, array(
                "colors" => array(
                    "primary" => $theme_primary,
                ),
            ));
        }

        // If set add custom logo
        if (isset($theme_logo) && $theme_logo != '') {
            $theme = array_merge($theme["header"], array(
                "logo" => $theme_logo,
            ));
        }

        $options = array(
            "theme" => $theme,
            // Will redirect automatically on Successful Payment Result
            "redirect" => array(
                "success" => true,
                "failure" => false
            ),
            "platform" => MobbexHelper::getPlatform(),
        );

        return $options;
    }

    public static function getReference($customer, $cart)
    {
        return 'ps_order_customer_' . $customer->id . '_cart_' . $cart->id . '_seed_' . mt_rand(100000, 999999);
    }

    public static function createCheckout($module, $cart, $customer)
    {
        $curl = curl_init();

        // Create an unique id
        $tracking_ref = MobbexHelper::getReference($customer, $cart);

        $reseller_id = Configuration::get(MobbexHelper::K_RESELLER_ID);

        if (isset($reseller_id) && $reseller_id != '') {
            // Add Reseller ID into the Reference
            $tracking_ref = $reseller_id . "-" . $tracking_ref;
        }

        $items = array();
        $products = $cart->getProducts(true);

        /* echo '<pre>';print_r($products); die; */

        //p($products);
        $arSplit = array();
        $idSeller = 0;
        foreach ($products as $product) {

            //p($product);
            $image = Image::getCover($product['id_product']);
            $link = new Link; //because getImageLInk is not static function
            $imagePath = $link->getImageLink($product['link_rewrite'], $image['id_image'], 'home_default');

            $items[] = array("image" => 'https://' . $imagePath, "description" => $product['name'], "quantity" => $product['cart_quantity'], "total" => round($product['price_wt'], 2));

            //Obtener id_seller segun el producto
            $sellerProduct = WkMpSellerProduct::getSellerProductByIdProduct($product['id_product']); /* echo '<pre>'; print_r($sellerProduct); die; */
            $idSeller = $sellerProduct['id_seller'];

            $seller = new WkMpSeller($idSeller);

            $arP[$idSeller]['tax_id'] = $seller->tax_identification_number;
            $arP[$idSeller]['pre'][]  =  round($product['price_wt'], 2);
            $arP[$idSeller]['total']  =  array_sum( $arP[$idSeller]['pre']);
            $arP[$idSeller]['reference'] = 'Pago CUIT '.$seller->tax_identification_number;
            
        }

        foreach($arP as $row){

            $fee = (($row['total']*Configuration::get('WK_MP_GLOBAL_COMMISSION'))/100);
            /* $total_seller = $row['total'] - $fee; */

            $split[] = array(
                "tax_id" => $row['tax_id'],
                "total" => $row['total'],
                "reference" => 'Pago CUIT '.$row['reference'],
                "fee" => $fee
            );
        }

        /* echo '<pre>'; print_r($split); die; */

        // Create data
        $data = array(
            'reference' => $tracking_ref,
            'currency' => 'ARS',
            'email' => $customer->email,
            'description' => 'Orden #' . $cart->id,
            // Test Mode
            'test' => Configuration::get(MobbexHelper::K_TEST_MODE),
            // notification / return => '&id_cart='.$cart->id.'&customer_id='.$customer->id
            'return_url' => MobbexHelper::getModuleUrl('notification', 'return', '&id_cart=' . $cart->id . '&customer_id=' . $customer->id),
            // notification / hook => '&id_cart='.$cart->id.'&customer_id='.$customer->id.'&key='.$customer->secure_key
            'items' => $items,
            //MobbexHelper::getModuleUrl('notification', 'hook', '&id_cart='.$cart->id.'&customer_id='.$customer->id.'&key='.$customer->secure_key),
            'webhook' => MobbexHelper::getWebhookUrl(array(
                "id_cart" => $cart->id,
                "customer_id" => $customer->id,
                "key" => $customer->secure_key,
            )),
            'options' => MobbexHelper::getOptions(),
            'redirect' => 0,
            'total' => (float)$cart->getOrderTotal(true, Cart::BOTH),
        );

        if (!$customer->isGuest()) {
            $data['customer'] = array(
                "name" => $customer->firstname . " " . $customer->lastname,
                "email" => $customer->email,
            );
        }

        $data['split'] = $split;        

        /* echo '<pre>'; print_r($data); die; */

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://mobbex.com/p/checkout/create",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => self::getHeaders(),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            d("cURL Error #:" . $err);
        } else {
            $res = json_decode($response, true);

            return $res['data']['url'];
        }
    }

    /**
     * Get the payment URL
     *
     * @return string
     */
    public static function getPaymentUrl()
    {
        $module = Context::getContext()->controller->module;
        $cart = Context::getContext()->cart;
        $customer = Context::getContext()->customer;

        return MobbexHelper::createCheckout($module, $cart, $customer);
    }

    public static function evaluateTransactionData($res)
    {
        // Get the Status
        $status = (int) $res['payment']['status']['code'];

        // Get the Reference ( Transaction ID )
        $transaction_id = $res['payment']['id'];

        $source_type = $res['payment']['source']['type'];
        $source_name = $res['payment']['source']['name'];

        $message = $res['payment']['status']['message'];

        $total = (float) $res['payment']['total'];

        // Create Result Array
        $result = array(
            'status' => $status,
            'orderStatus' => (int) Configuration::get(MobbexHelper::K_OS_WAITING),
            'message' => $message,
            'name' => $source_name,
            'transaction_id' => $transaction_id,
            'source_type' => $source_type,
            'total' => $total,
            'data' => $res,
        );

        if ($status == 200) {
            $result['orderStatus'] = (int) Configuration::get('PS_OS_PAYMENT');
        } elseif ($status == 1 && $source_type != 'card') {
            $result['orderStatus'] = (int) Configuration::get(MobbexHelper::K_OS_PENDING);
        } elseif ($status == 2 && $source_type != 'card') {
            $result['orderStatus'] = (int) Configuration::get(MobbexHelper::K_OS_WAITING);
        } else {
            $result['orderStatus'] = (int) Configuration::get(MobbexHelper::K_OS_REJECTED);
        }

        return $result;
    }

    public static function getTransaction($context, $transaction_id)
    {
        $curl = curl_init();

        // Create data
        $data = array(
            'id' => $transaction_id,
        );

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.mobbex.com/2.0/transactions/status",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => self::getHeaders(),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            echo "cURL Error #:" . $err;
        } else {
            $res = json_decode($response, true);

            return self::evaluateTransactionData($res['data']['transaction']);
        }
    }

    public static function getPsVersion() {
        if (_PS_VERSION_ >= 1.7) {
            return self::PS_17;
        } else {
            return self::PS_16;
        }
    }
}
