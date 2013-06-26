<?php

/**
 * FormBuilder Form Invoice Extended Class to support WooCommerce Orders
 * 
 * Call an instance of this Invoice and pass into it, the WC_Order you wish
 * to create an entry for.
 * 
 * @package FormBuilder\Form\Invoice
 * @subpackage WooCommerce Invoice
 * 
 * @author Leon Rowland <leon@rowland.nl>
 * @version 1.0.0
 */

class Woocommerce_Invoice extends \Pronamic\WP\Twinfield\FormBuilder\Form\Invoice {
	
	/**
	 * Holds the passed in WC_Order instance
	 * from instantiation
	 * 
	 * @var WC_Order
	 */
	private $order;
	
	/**
	 * Holds the customers ID. Has to be set
	 * with the setter
	 * 
	 * @var int
	 */
	private $customer_id;
	
	/**
	 * Holds the orders invoice type. For now
	 * it defaults to `FACTUUR` you can overide
	 * with the setter.
	 * @var string
	 */
	private $invoice_type = 'FACTUUR';
	
	/**
	 * Holds the WC Order in the class for use when
	 * fill_class is called.
	 * 
	 * The $order must be optional to support the base
	 * FormBuilder UI
	 * 
	 * @access public
	 * @param WC_Order $order OPTIONAL
	 * @return void
	 */
	public function __construct( WC_Order $order = null, $customer_id = null, $invoice_type = 'FACTUUR' ) {
		$this->order = $order;
		$this->customer_id = $customer_id;
		$this->invoice_type = $invoice_type;
	}

	/**
	 * Checks to see if a WC_Order is set, then get the
	 * data from prepare_invoice method and use that
	 * instead of the first passed data.
	 * 
	 * Calls parent::fill_class()
	 * 
	 * @overide
	 * 
	 * @access public
	 * @param array $data
	 * @return object
	 */
	public function fill_class( array $data ) {
		if ( null !== $this->order ) {
			$data = $this->prepare_invoice( $this->order );
		}
		
		return parent::fill_class( $data );
	}
	
	/**
	 * Sets some extra variables for the FormBuilder UI.
	 * 
	 * Currently sets a key of 'orders' that hold all
	 * the orders in WooCommerce. 
	 * 
	 * @overide
	 * 
	 * @access public
	 * @return void
	 */
	public function prepare_extra_variables() {
		parent::prepare_extra_variables();

		$all_orders = $this->get_all_orders();
		$this->set_extra_variables( 'orders', $all_orders );
	}

	/**
	 * Called from inside prepare_extra_variables that gives back the 
	 * array of all orders
	 * 
	 * @access public
	 * @return array
	 */
	public function get_all_orders() {
		$orders_query = new WP_Query( array(
			'post_type' => 'shop_order',
			'posts_per_page' => -1
		) );
		
		return $orders_query->posts;
	}
	
	/**
	 * Maps the WC_Order to the required array for the fill_class method
	 * 
	 * Is called from this child classes fill_class method, where if the
	 * WC_Order exists from instantiation then the data returned here overides
	 * the default data passed into fill_class()
	 * 
	 * You can just also call this public method and manually pass the data
	 * into fill_class or use for any other purpose.
	 * 
	 * @access public
	 * @param WC_Order $order
	 * @return array
	 */
	public function prepare_invoice( WC_Order $order = null ) {
		if ( $order )
			$this->order = $order;
		
		// Array for holding data for fill_class() method
		$fill_class_data = array();
		$fill_class_data['customerID']		 = $this->customer_id;
		$fill_class_data['invoiceType']		 = $this->invoice_type;
		$fill_class_data['invoiceNumber']	 = '';
		
		if ( $invoice_number = self::check_for_twinfield_invoice_number( $this->order->id ) ) {
			$fill_class_data['invoiceNumber'] = $invoice_number;
		}
		
		////////
		// Products
		////////
		
		// Get all ordered products
		$order_items = $this->order->get_items();
		
		// Prepare the lines for the form
		$fill_class_data['lines'] = array();
		
		// Go through all the products and add the items order information
		foreach ( $order_items as $item ) {
			
			$article_information = get_post_meta( $item['product_id'], '_twinfield_article', true );

			// Find and article and subarticle id if set
			$article_id		 = ( isset( $article_information['article_id'] ) ) ? $article_information['article_id'] : '';
			$subarticle_id	 = ( isset( $article_information['subarticle_id'] ) ) ? $article_information['subarticle_id'] : '';

			// Data for the lines
			$fill_class_data['lines'][] = array(
				'active' => true,
				'article'	 => $article_id,
				'subarticle' => $subarticle_id,
				'quantity' => $item['qty'],
				'unitspriceexcl' => $order->get_item_total( $item, false, false ),
				'vatcode' => $item['tax_class'],
				'freetext1' => $order->get_line_tax( $item )
			);
		}
		
		////////
		// Shipping
		////////
		
		// Get shipping article/subarticle
		$shipping_article_id = WoocommerceTwinfield_Integration::get_shipping_article_id( $order->shipping_method );
		$shipping_subarticle_id = WoocommerceTwinfield_Integration::get_shipping_subarticle_id( $order->shipping_method );
		
		$shipping_line = array(
			'active' => true,
			'article' => $shipping_article_id,
			'subarticle' => ( isset( $shipping_subarticle_id ) ? $shipping_subarticle_id : '' ),
			'quantity' => 1,
			'unitspriceexcl' => $order->get_shipping_tax(),
			'vatcode' => 'VN'
		);
		
		if ( WoocommerceTwinfield_Integration::add_shipping_method_to_freetext() )
			$shipping_line['freetext1'] = $order->get_shipping_method();
		
		// Shipping Fees
		$fill_class_data['lines'][] = $shipping_line;
		
		///////
		// Discounts
		///////
		
		// Get discount article/subarticle
		$discount_article_id = WoocommerceTwinfield_Integration::get_discount_article_id();
		$discount_subarticle_id = WoocommerceTwinfield_Integration::get_discount_subarticle_id();
		
		$discount_line = array(
			'active' => true,
			//'article' => $discount_article_id,
			//'subarticle' => ( isset( $discount_subarticle_id ) ? $discount_subarticle_id : '' ),
			'quantity' => 1,
			'unitspriceexcl' => '0.0',
			'vatcode' => 'VN',
			'freetext1' => $order->get_cart_discount()
		);
		
		// Discounts
		$fill_class_data['lines'][] = $discount_line;

		return $fill_class_data;
	}
	
	/**
	 * Should be called after a true response from the Invoice::submit() method
	 * call. It will add the twinfield_invoice_number to the post meta of the
	 * order.  It will prevent future calls to the sync button from adding new
	 * orders when it should update existing.
	 * 
	 * @access public
	 * @return int|bool
	 */
	public function successful() {
		// Map the response to an Invoice object
		$invoice = Pronamic\Twinfield\Invoice\Mapper\InvoiceMapper::map( $this->get_response() );
		
		// Check the response is an invoice object
		if ( $invoice instanceof \Pronamic\Twinfield\Invoice\Invoice ) {
			// Get the responded invoice number
			$invoice_number = $invoice->getInvoiceNumber();
			$customer_id = $invoice->getCustomer()->getID();
			
			// Add to the post meta, and return the invoice number
			update_post_meta( $this->order->id, '_woocommerce_twinfield_invoice_number', $invoice_number );
			update_post_meta( $this->order->id, '_woocommerce_twinfield_invoice_customer_id', $customer_id );
			
			return $invoice_number;
		} else {
			return false;
		}
	}
	
	/**
	 * Returns the recorded invoice number if one exists or false if not.
	 * 
	 * @access public
	 * @return int/false
	 */
	public static function check_for_twinfield_invoice_number( $order_id ) {
		return get_post_meta( $order_id, '_woocommerce_twinfield_invoice_number', true );
	}
	
	/**
	 * Returns the recorded customer id if one exists or false if not.
	 * @return int|false
	 */
	public static function check_for_twinfield_customer_id( $order_id ) {
		return get_post_meta( $order_id, '_woocommerce_twinfield_invoice_customer_id', true );
	}

}