<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2018
 * @package MShop
 * @subpackage Service
 */


namespace Aimeos\MShop\Service\Provider\Payment;


use Omnipay\Omnipay as OPay;


/**
 * Payment provider for Datatrans
 *
 * @package MShop
 * @subpackage Service
 */
class Datatrans
	extends \Aimeos\MShop\Service\Provider\Payment\OmniPay
	implements \Aimeos\MShop\Service\Provider\Payment\Iface
{
	/**
	 * Queries for status updates for the given order compare with the responseCode
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $order Order item
	 */
	public function query( \Aimeos\MShop\Order\Item\Iface $order )
	{
		$base = $this->getOrderBase( $order->getBaseId(), \Aimeos\MShop\Order\Item\Base\Base::PARTS_SERVICE );
		$data = ['transactionId' => $order->getId()];

		$response = $this->getProvider()->getTransaction( $data )->send();

		if( $response->isSuccessful() )
		{
			if( in_array( $response->getResponseCode(), [2, 3, 21] ) ) {
				$order->setPaymentStatus( \Aimeos\MShop\Order\Item\Base::PAY_RECEIVED );
			} elseif( $response->getResponseCode() == 1 ) {
				$order->setPaymentStatus( \Aimeos\MShop\Order\Item\Base::PAY_AUTHORIZED );
			}
		}
		elseif( method_exists($response, 'isPending') && $response->isPending() )
		{
			$order->setPaymentStatus( \Aimeos\MShop\Order\Item\Base::PAY_PENDING );
		}
		elseif( method_exists($response, 'isCancelled') && $response->isCancelled() )
		{
			$order->setPaymentStatus( \Aimeos\MShop\Order\Item\Base::PAY_CANCELED );
		}

		$this->saveTransationRef( $base, $response->getTransactionReference() );
		$this->saveOrder( $order );
	}


	/**
	 * Executes the payment again for the given order if supported.
	 * This requires support of the payment gateway and token based payment
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $order Order invoice object
	 * @return void
	 */
	public function repay( \Aimeos\MShop\Order\Item\Iface $order )
	{
		$base = $this->getOrderBase( $order->getBaseId() );

		if( ( $cfg = $this->getCustomerData( $base->getCustomerId(), 'repay' ) ) === null )
		{
			$msg = sprintf( 'No reoccurring payment data available for customer ID "%1$s"', $base->getCustomerId() );
			throw new \Aimeos\MShop\Service\Exception( $msg );
		}

		if( !isset( $cfg['token'] ) )
		{
			$msg = sprintf( 'No payment token available for customer ID "%1$s"', $base->getCustomerId() );
			throw new \Aimeos\MShop\Service\Exception( $msg );
		}

		$data = array(
			'transactionId' => $order->getId(),
			'currency' => $base->getPrice()->getCurrencyId(),
			'amount' => $this->getAmount( $base->getPrice() ),
			'cardReference' => $cfg['token'],
			'paymentPage' => false,
		);

		if( isset( $cfg['month'] ) && isset( $cfg['year'] ) )
		{
			$data['card'] = new \Omnipay\Common\CreditCard( [
				'expiryMonth' => $cfg['month'],
				'expiryYear' => $cfg['year'],
			] );
		}

		$response = $this->getXmlProvider()->purchase( $data )->send();

		if( $response->isSuccessful() )
		{
			$this->saveTransationRef( $base, $response->getTransactionReference() );
			$order->setPaymentStatus( \Aimeos\MShop\Order\Item\Base::PAY_RECEIVED );
			$this->saveOrder( $order );
		}
		else
		{
			$msg = ( method_exists( $response, 'getMessage' ) ? $response->getMessage() : '' );
			throw new \Aimeos\MShop\Service\Exception( sprintf( 'Token based payment failed: %1$s', $msg ) );
		}
	}


	/**
	 * Returns the value for the given configuration key
	 *
	 * @param string $key Configuration key name
	 * @param mixed $default Default value if no configuration is found
	 * @return mixed Configuration value
	 */
	protected function getValue( $key, $default = null )
	{
		switch( $key ) {
			case 'type': return 'Datatrans';
		}

		return parent::getValue( $key, $default );
	}


	/**
	 * Returns the Datatrans XML payment provider
	 *
	 * @return \Omnipay\Common\GatewayInterface Gateway provider object
	 */
	protected function getXmlProvider()
	{
		$provider = OPay::create('Datatrans\Xml');
		$provider->initialize( $this->getServiceItem()->getConfig() );

		return $provider;
	}
}
