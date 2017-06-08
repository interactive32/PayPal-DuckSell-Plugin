<?php namespace App\Plugins\Paypal;

require_once __DIR__ . '/sdk/vendor/autoload.php';

use App\Models\Product;
use App\Models\ProductTransaction;
use App\Models\Transaction;
use App\Models\TransactionMetadata;
use App\Models\TransactionUpdate;
use App\Models\User;
use App\Services\AmountService;
use Carbon\Carbon;
use PayPal\Api\PaymentExecution;
use PayPal\Api\Amount;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\Transaction as PPTrans;
use PayPal\Api\RedirectUrls;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use Event;
use Input;
use Lang;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Exception\PayPalConnectionException;
use PayPal\Rest\ApiContext;
use Redirect;
use Route;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Illuminate\Http\Request;
use App\Models\Log;
use Validator;


Event::listen('App\Events\PluginMenu', function($event)
{
    return '<li class="'.(getRouteName() == 'paypal@setup' ? 'active' : '').'"><a href="'.url('/paypal').'"><i class="fa fa-credit-card"></i><span>PayPal</span></a></li>';
});

Event::listen('App\Events\ContentProductsEdit', function($event)
{
    $options = Plugin::getData(PluginSetup::$plugin_name);
    $mode = $options->where('key', 'mode')->first() ? $options->where('key', 'mode')->first()->value : false;

    $product = Product::findOrFail($event->product_id);

    if(APP_VERSION < PluginSetup::$minimum_version || !$mode) return;

    if(!$product->getPrice()) {
        return '
    <div class="box box-success">
        <div class="box-header">
            <h3 class="box-title">PayPal Integration</h3>
        </div>
        <div class="box-body">
            <p>
              Note: Please set product\'s price to see buy button code
            </p>
        </div>
    </div>';
    }

    $purchase_link = url(PluginSetup::$buy_link.'/'.$event->product_id);

    $form_button = '<form target="paypal" action="'.$purchase_link.'" method="get">
    <input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_buynowCC_LG.gif" border="0" name="submit">
</form>';


    $bootstrap_button = '<a href="'.$purchase_link.'" class="btn btn-primary">Buy Now</a>';

    return '
    <div class="box box-success">
        <div class="box-header">
            <h3 class="box-title">PayPal Integration</h3>
        </div>
        <div class="box-body">
            <p>
              <button data-target="#paypalModal" data-toggle="modal" class="btn btn-secondary pull-right" type="button">Buy Button Code</button>
            </p>
        </div>
    </div>
    
    <!-- Modal -->
    <div class="modal fade" id="paypalModal" tabindex="-1" role="dialog" aria-labelledby="paypalModalLabel">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="paypalModalLabel">PayPal Buy Button Code</h4>
          </div>
          <div class="modal-body">
          
          <div class="nav-tabs-custom no-shadow">
						<ul class="nav nav-tabs">
							<li class="active"><a href="#button_1" data-toggle="tab">Classic Form Button</a></li>
							<li><a href="#button_2" data-toggle="tab">Bootstrap Button</a></li>
							<li><a href="#button_3" data-toggle="tab">Email</a></li>
						</ul>
						<br>
						<div class="tab-content">
							<div class="tab-pane active" id="button_1">
                                <h4>HTML Code:</h4>
                                <pre>'.htmlentities($form_button).'</pre>
                                <hr>
                                <h4>Preview:</h4>
                                <br>
                                <div>'.$form_button.'</div>
                            </div>
                            <div class="tab-pane" id="button_2">
								<h4>Bootstrap HTML Code:</h4>
                                <pre>'.htmlentities($bootstrap_button).'</pre>
                                <hr>
                                <h4>Preview:</h4>
                                <br>
                                <div>'.$bootstrap_button.'</div>
							</div>
							<div class="tab-pane" id="button_3">
								<div class="form-group">
									<h4>Email Link:</h4>
                                <pre>'.$purchase_link.'</pre>
								</div>
							</div>
						</div>
  		  </div>

		  </div>		
          <div class="modal-footer">

          </div>
        </div>
      </div>
    </div>
    ';
});



Event::listen('App\Events\Routes', function($event)
{
    Route::group(['middleware' => ['csrf', 'admin']], function()
    {
        Route::get(PluginSetup::$base_url, '\App\Plugins\Paypal\PaypalController@setup');
        Route::post(PluginSetup::$base_url, '\App\Plugins\Paypal\PaypalController@update');
    });

    Route::get(PluginSetup::$buy_link.'/{product_id}', '\App\Plugins\Paypal\PaypalController@placeOrder');
    Route::get('paypal_return', '\App\Plugins\Paypal\PaypalController@returnUrl');
    Route::get('paypal_thank_you', '\App\Plugins\Paypal\PaypalController@thankYou');

});


class PaypalController extends Controller
{
    public $options;

    public function __construct()
    {
        $this->options = Plugin::getData(PluginSetup::$plugin_name);

        parent::__construct();
    }

    private function getOption($name)
    {
        return $this->options->where('key', $name)->first() ? $this->options->where('key', $name)->first()->value : false;
    }

    public function setup()
    {
        if(APP_VERSION < PluginSetup::$minimum_version) {
            return view(basename(__DIR__) . '/views/requirements');
        };

        return view(basename(__DIR__) . '/views/setup')->with([
            'options' => $this->options,
            'default_template' => "Hi,\n\nThank you for purchasing {{ \$item_name }}. We will contact you shortly.\n\nRegards,\nThe Team\n",
        ]);
    }

    public function update()
    {
        $rules = [
            'mode' => 'required',
            'client_id' => 'required',
            'client_secret' => 'required',
            'currency' => 'required',
        ];

        $validator = Validator::make(Input::all(), $rules);

        if ($validator->fails()) {
            return Redirect::to(PluginSetup::$base_url)
                ->withErrors($validator)
                ->withInput();
        } else {

            foreach (Input::except(['_token']) as $key => $value) {

                try {
                    Plugin::updateValue(PluginSetup::$plugin_name, $key, trim($value));
                } catch (\Exception $e) {
                    Log::writeException($e);
                    return Redirect::to(PluginSetup::$base_url)
                        ->withErrors($e->getMessage())
                        ->withInput();
                }
            }

            flash()->success(trans('success'));
            return Redirect::to(PluginSetup::$base_url);

        }
    }

    public function thankYou()
    {
        return view(basename(__DIR__).'/views/thankyou');
    }

    public function placeOrder($product_id)
    {
        $OrderModel = new OrderModel();

        $product = Product::find($product_id);

        if(!$product || !$product->getPrice()) {
            die('product not found / no price');
        }

        $currency = $this->getOption('currency');
        $total = $product->getPrice();

        $order = $OrderModel->createOrder(
            $product->id,
            $total,
            $currency,
            $product->name
        );

        try {

            $payment = $this->makePaymentUsingPayPal(
                $order->total / 100,
                $order->currency,
                $order->description,
                url('paypal_return?orderId=' . $order->row_id));

        } catch (PayPalConnectionException $e) {
            Log::writeException($e);
            Log::write('PayPalConnectionException', $e->getData());
            echo 'Connection Error! Please check logs.';
            die;
        } catch (\Exception $e) {
            Log::writeException($e);
            echo 'Connection Error! Please check logs.';
            die;
        }


        $order->state = $payment->getState();
        $order->payment_id = $payment->getId();

        $OrderModel->saveOrder($order);

        return Redirect::to($this->getLink($payment->getLinks(), "approval_url").'&useraction=commit');
    }

    public function executePayment($paymentId, $payerId) {

        $payment = Payment::get($paymentId, $this->getApiContext());

        $paymentExecution = new PaymentExecution();
        $paymentExecution->setPayerId($payerId);
        $payment = $payment->execute($paymentExecution, $this->getApiContext());

        return $payment;
    }

    public function returnUrl()
    {

        if(isset($_GET['PayerID']) && isset($_GET['orderId'])) {

            $orderId = $_GET['orderId'];

            $OrderModel = new OrderModel();
            $Order = $OrderModel->getOrder($orderId);

            try {

                $payment = $this->executePayment($Order->payment_id, $_GET['PayerID']);

                $Order->state = $payment->getState();
                $OrderModel->saveOrder($Order);

                $transaction = $this->createTransaction($payment, $Order);

            } catch (\Exception $e) {
                Log::writeException($e);
                echo $message = $e->getMessage();
                die;
            }

            if(!$transaction) {
                Log::write('log_cannot_create_transaction', json_encode($Order), true, Log::TYPE_CRITICAL);
                die;
            }

            if(config('global.allow-direct-links')) {
                $direct_link = url('/download') .'?q='. $transaction->hash;
                return Redirect::to($direct_link);
            }

            return Redirect::to(url('/paypal_thank_you'));

        } else {
            return view(basename(__DIR__).'/views/canceled');
        }

    }


    public function makePaymentUsingPayPal($total, $currency, $paymentDesc, $returnUrl) {

        $payer = new Payer();
        $payer->setPaymentMethod("paypal");

        // Specify the payment amount.
        $amount = new Amount();
        $amount->setCurrency($currency);
        $amount->setTotal($total);

        $transaction = new PPTrans();
        $transaction->setAmount($amount);
        $transaction->setDescription($paymentDesc);

        $redirectUrls = new RedirectUrls();
        $redirectUrls->setReturnUrl($returnUrl);
        $redirectUrls->setCancelUrl($returnUrl);

        $payment = new Payment();
        $payment->setRedirectUrls($redirectUrls);
        $payment->setIntent("sale");
        $payment->setPayer($payer);
        $payment->setTransactions(array($transaction));

        $payment->create($this->getApiContext());

        return $payment;
    }

    private function createTransaction(Payment $payment, Order $order)
    {
        $User = new User();
        $Product = new Product();
        $Transaction = new Transaction();
        $ProductTransaction = new ProductTransaction();
        $TransactionUpdate = new TransactionUpdate();
        $TransactionMetadata = new TransactionMetadata();

        // get existing or create new customer
        $customer = $User->getOrCreateCustomer(
            $payment->getPayer()->getPayerInfo()->getEmail(),
            $payment->getPayer()->getPayerInfo()->getFirstName() .' '.$payment->getPayer()->getPayerInfo()->getLastName(),
            $this->getCustomerDetails($payment),
            $this->getCustomerMetaData($payment)
        );

        $product = $Product->findOrFail($order->product_id);
        $amount = $this->getAmount(new AmountService(), $order);

        if(!$customer) {
            Log::write('log_cannot_create_customer', $payment->getPayer()->getPayerInfo()->getEmail(), true, Log::TYPE_CRITICAL);
            return false;
        }

        // in case this is returning customer, update details with fresh set
        $customer->details = $this->getCustomerDetails($payment);
        $customer->save();

        if($Transaction->getTransactionByExternalSaleId($order->payment_id)) {
            Log::write('log_cannot_create_order_exist', $order->payment_id, true, Log::TYPE_CRITICAL);
            return false;
        }

        // create transaction
        $transaction = $Transaction->createTransaction(
            PluginSetup::$plugin_name,
            $customer->id,
            $amount,
            $this->getInvoiceStatus($order),
            $order->payment_id);

        if(!$transaction) {
            Log::write('log_cannot_create_transaction', json_encode($order), true, Log::TYPE_CRITICAL);
            return false;
        }

        // add metadata
        $TransactionMetadata->addMetadata($transaction->id, $this->getTransactionMetaData($payment, $order));

        // set initial statuses
        $TransactionUpdate->updateTransaction(
            $transaction->id,
            'trx_update_invoice_status',
            PluginSetup::$plugin_name,
            $this->getInvoiceStatus($order)
        );

        // add products
        $ProductTransaction->addProductToTransaction($product->id, $amount, $transaction->id);

        $Transaction->sendPurchaseInformationEmail($transaction->hash);

        return $transaction;
    }


    private function getAmount(AmountService $amount, Order $order)
    {
        $amount->setProcessorCurrency($order->currency);
        $amount->setProcessorAmount($order->total / 100);

        $amount->setListedCurrency($order->currency);
        $amount->setListedAmount($order->total / 100);

        $amount->setCustomerCurrency($order->currency);
        $amount->setCustomerAmount($order->total / 100);

        return $amount;
    }

    private function getTransactionMetaData(Payment $payment, Order $order)
    {
        $metadata = [];

        // mandatory fields (translatable keys)
        $metadata['external_sale_id'] = $order->payment_id;
        $metadata['timestamp'] = $order->created_at;
        $metadata['sale_date_placed'] = $payment->getCreateTime();

        $transactions = $payment->getTransactions();
        $relatedResources = $transactions[0]->getRelatedResources();
        $sale = $relatedResources[0]->getSale();

        $metadata['transaction_fee'] = $sale->getTransactionFee()->getValue();

        return $metadata;
    }

    private function getInvoiceStatus(Order $order)
    {
        // Valid Values: ["created", "approved", "failed", "partially_completed", "in_progress"]
        switch ($order->state) {


            case 'approved':
                $status = Transaction::STATUS_APPROVED;
                break;

            case 'failed':
                $status = Transaction::STATUS_REFUNDED;
                break;

            case 'created':
                $status = Transaction::STATUS_CANCELED;
                break;

            case 'partially_completed':
            case 'in_progress':
                $status = Transaction::STATUS_PENDING;
                break;

            default:
                $status = Transaction::STATUS_CANCELED;
                break;
        }

        return $status;
    }

    private function getCustomerDetails(Payment $payment)
    {
        if(!$payment->getPayer()->getPayerInfo()->getBillingAddress()) {
            return $payment->getPayer()->getPayerInfo()->getFirstName() .' '. $payment->getPayer()->getPayerInfo()->getLastName()."\n";
        }

        return
            $payment->getPayer()->getPayerInfo()->getFirstName() .' '. $payment->getPayer()->getPayerInfo()->getLastName()."\n".
            ($payment->getPayer()->getPayerInfo()->getBillingAddress()->getLine1() ? $payment->getPayer()->getPayerInfo()->getBillingAddress()->getLine1()."\n" : '').
            ($payment->getPayer()->getPayerInfo()->getBillingAddress()->getLine2() ? $payment->getPayer()->getPayerInfo()->getBillingAddress()->getLine2()."\n" : '').
            ($payment->getPayer()->getPayerInfo()->getBillingAddress()->getCity() ? $payment->getPayer()->getPayerInfo()->getBillingAddress()->getCity()."\n" : '').
            ($payment->getPayer()->getPayerInfo()->getBillingAddress()->getState() ? $payment->getPayer()->getPayerInfo()->getBillingAddress()->getState()."\n" : '').
            ($payment->getPayer()->getPayerInfo()->getBillingAddress()->getPostalCode() ? $payment->getPayer()->getPayerInfo()->getBillingAddress()->getPostalCode()."\n" : '').
            ($payment->getPayer()->getPayerInfo()->getBillingAddress()->getCountryCode() ? $payment->getPayer()->getPayerInfo()->getBillingAddress()->getCountryCode()."\n" : '').
            ($payment->getPayer()->getPayerInfo()->getBillingAddress()->getPhone() ? $payment->getPayer()->getPayerInfo()->getBillingAddress()->getPhone()."\n" : '')
            ;
    }


    private function getCustomerMetaData(Payment $payment)
    {
        $metadata = [];

        $metadata['first_name'] = $payment->getPayer()->getPayerInfo()->getFirstName();
        $metadata['last_name'] = $payment->getPayer()->getPayerInfo()->getLastName();
        $metadata['name'] = $payment->getPayer()->getPayerInfo()->getFirstName() .' '. $payment->getPayer()->getPayerInfo()->getLastName();
        $metadata['email'] = $payment->getPayer()->getPayerInfo()->getEmail();
        $metadata['details'] = $this->getCustomerDetails($payment);

        return $metadata;
    }



    public function getApiContext()
    {


        $apiContext = new ApiContext(
            new OAuthTokenCredential(
                $this->getOption('client_id'),
                $this->getOption('client_secret')
            )
        );

        $apiContext->setConfig(
            array(
                'mode' => $this->getOption('mode') == 'live' ? 'live' : 'sandbox', // sandbox or live
                'log.LogEnabled' => false,
                'log.FileName' => __DIR__ . '/PayPal.log',
                'log.LogLevel' => 'INFO', // INFO/DEBUG/ERROR PLEASE USE `INFO` LEVEL FOR LOGGING IN LIVE ENVIRONMENTS
                'cache.enabled' => false, // very good performance improvement if set to true
                // 'http.CURLOPT_CONNECTTIMEOUT' => 30
                // 'http.headers.PayPal-Partner-Attribution-Id' => '123123123'
                //'log.AdapterFactory' => '\PayPal\Log\DefaultLogFactory' // Factory class implementing \PayPal\Log\PayPalLogFactory
            )
        );

        return $apiContext;
    }

    public function getLink(array $links, $type) {
        foreach($links as $link) {
            if($link->getRel() == $type) {
                return $link->getHref();
            }
        }
        return "";
    }
}

class Order {

    public $row_id;

    public $order_id;
    public $product_id;
    public $total;
    public $currency;
    public $description;
    public $state;
    public $payment_id;

    public $created_at;

}

class OrderModel {

    public $row;

    public function getOrder($orderId)
    {

        $this->row = Plugin::where('plugin_name', PluginSetup::$plugin_name)->where('id', $orderId)->firstOrFail();

        $order_decoded = json_decode($this->row->value);

        // unpack
        $order = new Order();
        $order->row_id = $this->row->id;
        $order->order_id = $order_decoded->order_id;
        $order->product_id = $order_decoded->product_id;
        $order->total = $order_decoded->total;
        $order->currency = $order_decoded->currency;
        $order->description = $order_decoded->description;
        $order->state = $order_decoded->state;
        $order->payment_id = $order_decoded->payment_id;
        $order->created_at = $order_decoded->created_at;

        return $order;
    }

    public function createOrder($product_id, $total, $currency, $description)
    {
        $order = new Order();

        $order->product_id = $product_id;
        $order->total = $total;
        $order->currency = $currency;
        $order->description = $description;
        $order->created_at = Carbon::create()->toDateTimeString();

        $this->row = new Plugin();
        $this->row->plugin_name = PluginSetup::$plugin_name;
        $this->row->key = 'order';
        $this->row->value = json_encode($order);
        $this->row->save();

        $order->row_id = $this->row->id;

        return $order;

    }

    public function saveOrder(Order $order)
    {
        $this->row->value = json_encode($order);
        $this->row->save();
    }

}

class PluginSetup {

    public static $plugin_name = 'paypal';
    public static $base_url = 'paypal';
    public static $minimum_version = '1.5';
    public static $buy_link = 'buy';
}


class Trans {

    public static $translator;

    public static function translator()
    {
        if (static::$translator === null) {

            $locale = Lang::getLocale();
            $file = __DIR__.'/lang/'.$locale.'.php';

            if(!file_exists($file)) {
                // fallback to english
                $file = __DIR__.'/lang/en.php';
            }

            static::$translator = new Translator($locale);
            static::$translator->addLoader('array', new ArrayLoader());
            static::$translator->setLocale($locale);

            static::$translator->addResource('array', require $file, $locale);
        }

        return static::$translator;
    }
}