<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Table\Table;

class os_mercadopago extends MPFPayment
{
	/**
	 * Constructor
	 *
	 * @param   \Joomla\Registry\Registry  $params
	 * @param   array                      $config
	 */
	public function __construct($params, $config = [])
	{
		// Use sandbox API keys if available
		if (!$params->get('mode', 1))
		{
			$keys = [
				'public_key',
				'access_token',
				'app_id',
			];

			foreach ($keys as $key)
			{
				if ($params->get('sandbox_' . $key))
				{
					$params->set($key, $params->get('sandbox_' . $key));
				}
			}
		}

		parent::__construct($params, $config);
	}

	/**
	 * Process payment
	 *
	 * @param   OSMembershipTableSubscriber  $row
	 * @param   array                        $data
	 */
	public function processPayment($row, $data)
	{
		require __DIR__ . '/mercadopago/vendor/autoload.php';

		MercadoPago\SDK::setAccessToken($this->params->get('access_token'));

		$siteUrl     = Uri::root();
		$app         = Factory::getApplication();
		$Itemid      = $app->input->getInt('Itemid', 0);
		$completeUrl = $this->getPaymentCompleteUrl($row, $Itemid, true);

		// Create a preference object
		$preference = new MercadoPago\Preference();

		$item                         = new MercadoPago\Item();
		$item->title                  = $data['item_name'];
		$item->quantity               = 1;
		$item->unit_price             = (int) $data['amount'];
		$preference->items            = [$item];
		$preference->notification_url = $siteUrl . 'index.php?option=com_osmembership&task=payment_confirm&payment_method=os_mercadopago&id=' . $row->id . OSMembershipHelper::getLangLink();

		$preference->back_urls = [
			"success" => $completeUrl,
			"failure" => $this->getPaymentFailureUrl($row, $Itemid, true),
			"pending" => $completeUrl,
		];

		$preference->external_reference = $this->params->get('order_prefix', '') . $row->id;

		$payer             = new MercadoPago\Payer();
		$payer->first_name = $row->first_name;
		$payer->last_name  = $row->last_name;
		$payer->email      = $row->email;

		$preference->payer = $payer;
		$preference->save();

		$app->redirect($preference->init_point);
	}

	/**
	 * Verify onetime subscription payment
	 *
	 * @return bool
	 */
	public function verifyPayment()
	{
		if (!$this->validate())
		{
			return false;
		}

		/* @var OSMembershipTableSubscriber $row */
		$row           = Table::getInstance('Subscriber', 'OSMembershipTable');
		$id            = $this->notificationData['id'];
		$transactionId = $this->notificationData['transaction_id'];

		// Make sure each transaction is only processed once
		if ($transactionId && OSMembershipHelper::isTransactionProcessed($transactionId))
		{
			$this->logGatewayData(sprintf('Transaction Processed %s', $transactionId));

			return false;
		}

		$amount = floatval($this->notificationData['amount']);

		if ($amount < 0)
		{
			$this->logGatewayData(sprintf('Invalid Subscription Amount %s', $amount));

			return false;
		}

		if (!$row->load($id))
		{
			$this->logGatewayData(sprintf('Invalid Subscription ID %s', $id));

			return false;
		}

		if ($row->published)
		{
			$this->logGatewayData(sprintf('Subscription ID %s was published before', $id));

			return false;
		}

		// Accept 0.05$ difference to avoid bug causes by rounding
		if (($row->payment_amount - $amount) > 0.05)
		{
			$this->logGatewayData(sprintf('Subscription ID %s has invalid payment amount', $id));

			return false;
		}

		$this->onPaymentSuccess($row, $transactionId);
	}


	private function validate()
	{
		require __DIR__ . '/mercadopago/vendor/autoload.php';

		MercadoPago\SDK::setAccessToken($this->params->get('access_token'));

		$input = Factory::getApplication()->input->get;

		$this->notificationData = $_GET;

		$this->logGatewayData();

		if ($input->getString('type') != 'payment')
		{
			return false;
		}

		$dataId = $input->getString('data_id');

		if (!$dataId)
		{
			return false;
		}


		$payment = MercadoPago\Payment::find_by_id($dataId);

		// Get the payment and the corresponding merchant_order reported by the IPN.
		$merchant_order = MercadoPago\MerchantOrder::find_by_id($payment->order->id);

		if (!$merchant_order)
		{
			return false;
		}

		$paidAmount = 0;

		foreach ($merchant_order->payments as $payment)
		{
			if ($payment->status == 'approved')
			{
				$paidAmount += $payment->transaction_amount;
			}
		}

		$this->notificationData['id']             = $input->getInt('id', 0);
		$this->notificationData['amount']         = $paidAmount;
		$this->notificationData['transaction_id'] = $dataId;

		// If the payment's transaction amount is equal (or bigger) than the merchant_order's amount you can release your items
		if ($paidAmount >= $merchant_order->total_amount)
		{
			$this->logGatewayData(sprintf('Status:%s,ID:%s,Paid Amount:%s,Order Amount:%s', 'Success', $dataId, $paidAmount,
				$merchant_order->total_amount));

			return true;
		}

		$this->logGatewayData(sprintf('Status:%s,ID:%s,Paid Amount:%s,Order Amount:%s', 'Fail', $dataId, $paidAmount, $merchant_order->total_amount));

		return false;
	}
}