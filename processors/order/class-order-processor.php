<?php

/**
 * CiviCRM Caldera Forms Order Processor Class.
 *
 * @since 0.4.4
 */
class CiviCRM_Caldera_Forms_Order_Processor {

	/**
	 * Plugin reference.
	 *
	 * @since 0.4.4
	 * @access public
	 * @var object $plugin The plugin instance
	 */
	public $plugin;

	/**
	 * Contact link.
	 * 
	 * @since 0.4.4
	 * @access protected
	 * @var string $contact_link The contact link
	 */
	protected $contact_link;

	/**
	 * Payment processor fee.
	 * 
	 * @since 0.4.4
	 * @access protected
	 * @var string $fee The fee
	 */
	protected $charge_metadata;

	/**
	 * Is pay later.
	 *
	 * @since 0.4.4
	 * @access public
	 * @var boolean $is_pay_later
	 */
	public $is_pay_later;

	/**
	 * The order result.
	 *
	 * @since 0.4.4
	 * @access public
	 * @var array $order
	 */
	public $order;

	/**
	 * The processor key.
	 *
	 * @since 0.4.4
	 * @access public
	 * @var str $key_name The processor key
	 */
	public $key_name = 'civicrm_order';

	/**
	 * Initialises this object.
	 *
	 * @since 0.4.4
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		// register this processor
		add_filter( 'caldera_forms_get_form_processors', [ $this, 'register_processor' ] );
		// add payment processor hooks
		add_action( 'caldera_forms_submit_pre_process_start', [ $this, 'add_payment_processor_hooks' ], 10, 3 );

	}

	/**
	 * Adds this processor to Caldera Forms.
	 *
	 * @since 0.4.4
	 *
	 * @uses 'caldera_forms_get_form_processors' filter
	 *
	 * @param array $processors The existing processors
	 * @return array $processors The modified processors
	 */
	public function register_processor( $processors ) {

		$processors[$this->key_name] = [
			'name' => __( 'CiviCRM Order', 'caldera-forms-civicrm' ),
			'description' => __( 'Add CiviCRM Order (Contribution with multiple Line Items, ie Events registrations, Donations, Memberships, etc.)', 'caldera-forms-civicrm' ),
			'author' => 'Andrei Mondoc',
			'template' => CF_CIVICRM_INTEGRATION_PATH . 'processors/order/order_config.php',
			'single' => true,
			'pre_processor' =>  [ $this, 'pre_processor' ],
			'processor' => [ $this, 'processor' ],
			'post_processor' => [ $this, 'post_processor'],
			'magic_tags' => [ 'order_id' ]
		];

		return $processors;

	}

	/**
	 * Form pre processor callback.
	 *
	 * @since 0.4.4
	 *
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param string $processid The process id
	 */
	public function pre_processor( $config, $form, $processid ) {
		
	}

	/**
	 * Form processor callback.
	 *
	 * @since 0.4.4
	 *
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param string $processid The process id
	 */
	public function processor( $config, $form, $processid ) {
		
		global $transdata;

		$transient = $this->plugin->transient->get();
		$this->contact_link = 'cid_' . $config['contact_link'];

		$config_line_items = $config['line_items'];
		unset( $config['line_items'] );
		
		// Get form values
		$form_values = $this->plugin->helper->map_fields_to_processor( $config, $form, $form_values );

		$form_values['financial_type_id'] = $config['financial_type_id'];
		$form_values['contribution_status_id'] = $config['contribution_status_id'];
		$form_values['payment_instrument_id'] = ! isset( $config['is_mapped_field'] ) ?
			$config['payment_instrument_id'] :
			$form_values['mapped_payment_instrument_id'];
		
		$form_values['currency'] = $config['currency'];

		// $form_values['receipt_date'] = date( 'YmdHis' );
		
		// contribution page for reciepts
		if ( isset( $config['contribution_page_id'] ) )
			$form_values['contribution_page_id'] = $config['contribution_page_id'];

		// is pay later
		if ( isset( $config['is_pay_later'] ) && in_array( $form_values['payment_instrument_id'], [$config['is_pay_later']] ) ) {
			$this->is_pay_later = true;
			$form_values['contribution_status_id'] = 'Pending';
			$form_values['is_pay_later'] = 1; // has to be set, if not we get a (Incomplete transaction)
			unset( $form_values['trxn_id'] );
		}
		
		// source
		if( ! isset( $form_values['source'] ) )
			$form_values['source'] = $form['name'];
		
		$form_values['contact_id'] = $transient->contacts->{$this->contact_link};
		
		// line items
		$line_items = [];
		$count = 0;
		$total_tax_amount = 0;
		foreach ( $config_line_items as $item => $processor ) {
			if( ! empty( $processor ) ) {
				$processor = Caldera_Forms::do_magic_tags( $processor );
				// line item is enabled and is not empty
				if ( ! strpos( $processor, 'civicrm_line_item' ) && ! empty( ( array ) $transient->line_items->$processor ) ) {
					$line_items[$count] = $transient->line_items->$processor->params;
					// tax amount
					if ( isset( $line_items[$count]['line_item'][0]['tax_amount'] ) && $this->plugin->helper->get_tax_settings()['invoicing'] ) $total_tax_amount += $line_items[$count]['line_item'][0]['tax_amount'];
					// membership is pay later
					if ( isset( $line_items[$count]['params']['membership_type_id'] ) && $this->is_pay_later ) {
							// set membership as pending
							$line_items[$count]['params']['status_id'] = 'Pending';
							$line_items[$count]['params']['is_override'] = 1;
					}
					// participant is pay later
					if ( isset( $line_items[$count]['params']['event_id'] ) && $this->is_pay_later ) {
							// set participant as pending
							$line_items[$count]['params']['status_id'] = 'Pending from pay later';
					}
					$count++;
				}
			} else {
				unset( $config_line_items[$item] );
			}
		}

		// add total tax amount
		if ( $total_tax_amount )
			$form_values['tax_amount'] = $total_tax_amount;

		$form_values['line_items'] = $line_items;

		// stripe metadata
		if ( $this->charge_metadata ) $form_values = array_merge( $form_values, $this->charge_metadata );

		// FIXME
		// move this into its own finction
		// 
		// authorize metadata
		if( isset( $transdata[$transdata['transient']]['transaction_data']->transaction_id ) ) {
			$metadata = [
				'trxn_id' => $transdata[$transdata['transient']]['transaction_data']->transaction_id,
				'card_type_id' => $this->get_option_by_label( $transdata[$transdata['transient']]['transaction_data']->card_type ),
				'credit_card_type' => $transdata[$transdata['transient']]['transaction_data']->card_type,
				'pan_truncation' => str_replace( 'X', '', $transdata[$transdata['transient']]['transaction_data']->account_number ),
			];
			$form_values = array_merge( $form_values, $metadata );
		}

		if ( $this->has_participant( $form_values ) ) {
			$create_order = $this->process_participant_order( $form_values, $config );
		} else {
			$create_order = $this->process_order( $form_values, $config );
		}

		// return order_id magic tag
		if ( is_array( $create_order ) && ! $create_order['is_error'] )
			return $create_order['id'];

	}

	/**
	 * Form post processor callback.
	 *
	 * @since 0.4.4
	 *
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param string $processid The process id
	 */
	public function post_processor( $config, $form, $processid ) {

		global $transdata;
		$transient = $this->plugin->transient->get();

		// preserve join dates 
		$this->preserve_membership_join_date( $form );

		$line_items = civicrm_api3( 'LineItem', 'get', [
				'contribution_id' => $this->order['id']
			] );

		$this->order = array_merge( $this->order, [ 'line_items' => $line_items['values'] ] );

		$this->track_cividiscounts( $this->order );

		// send confirmation/receipt
		$this->maybe_send_confirmation( $this->order, $config );

		if ( true ) { //$config['is_thank_you'] ) {
			add_filter( 'caldera_forms_ajax_return', function( $out, $_form ) use ( $transdata, $transient ){

				/**
				 * Filter thank you template path.
				 *
				 * @since 0.4.4
				 * 
				 * @param string $template_path The template path
				 * @param array $form Form config
				 */
				$template_path = apply_filters( 'cfc_order_thank_you_template_path', CF_CIVICRM_INTEGRATION_PATH . 'templates/thank-you.php', $_form );

				$form_values = Caldera_Forms::get_submission_data( $_form );

				$data = [
					'values' => $form_values,
					'form' => $_form,
					'transdata' => $transdata,
					'transient' => $transient
				];

				$html = $this->plugin->html->generate( $data, $template_path, $this->plugin );

				$out['html'] = $out['html'] . $html;

				return $out;

			}, 10, 2 );
		}


		/**
		 * Runs when Order processor is post_processed if an order has been created.
		 *
		 * @since 1.0
		 * @param array|bool $order The created order result, or false
		 * @param array $config The processor config
		 * @param array $form The form config
		 * @param string $processid The process id
		 */
		do_action( 'cfc_order_post_processor', $this->order, $config, $form, $processid );

	}

	/**
	 * Process order.
	 *
	 * @since 1.0
	 * @param array $form_values The submitted form values
	 * @param  [type] $config      [description]
	 * @return [type]              [description]
	 */
	public function process_order( $form_values, $config ) {

		global $transdata;

		if ( ! is_array( $form_values ) ) return;

		try {

			$order = civicrm_api3( 'Order', 'create', $form_values );

			$this->order = ( $order['count'] && ! $order['is_error'] ) ? $order['values'][$order['id']] : false;

			$this->create_premium( $form_values, $config );

		} catch ( CiviCRM_API3_Exception $e ) {
			$transdata['error'] = true;
			$transdata['note'] = $e->getMessage() . '<br><br><pre' . $e->getTraceAsString() . '</pre>';
		}
	}

	public function process_participant_order( $form_values, $config ) {

		global $transdata;

		if ( ! is_array( $form_values ) ) return;

		$primary_participant_id = false;

		// process entities and format line items
		$line_items = array_reduce( $form_values['line_items'], function( $line_items, $line_item ) use ( &$primary_participant_id ) {

			$item = [];

			if ( isset( $line_item['params'] ) ) {
				$entity = civicrm_api3( str_replace( 'civicrm_', '', $line_item['line_item'][0]['entity_table'] ), 'create', $line_item['params'] );
				$item['entity_id'] = $entity['id'];

				if ( ! $primary_participant_id && $line_item['line_item'][0]['entity_table'] == 'civicrm_participant' )
					$primary_participant_id = $entity['id'];
			}

			unset( $line_item['params'] );

			// multiple choices price field
			if ( count( $line_item['line_item'] ) > 1 ) {
				foreach ( $line_item['line_item'] as $key => $sub_item ) {
					$line_items[] = $sub_item; 
				}
			} else {
				$line_items[] = array_merge( $line_item['line_item'][0], $item );
			}

			return $line_items;

		}, [] );

		$modified_line_items = array_reduce( $line_items, function( $line_items, $line_item ) use ( $primary_participant_id ) {

			$line_item['entity_id'] = $primary_participant_id;
			$line_item['entity_table'] = 'civicrm_participant';

			$line_items[] = $line_item;

			return $line_items;

		}, [] );

		// all price fields should be from the same price set, retrieve from the first index
		$price_field = $this->plugin->helper->get_price_set_column_by_id( $line_items[0]['price_field_id'], 'price_field' );

		$form_values['line_item'] = [ $price_field['price_set_id'] => $modified_line_items ];
		$form_values['participant_id'] = $primary_participant_id;
		$form_values['contribution_mode'] = 'participant';

		unset( $form_values['line_items'] );

		try {

			$contribution = civicrm_api3( 'Contribution', 'create', $form_values );

			$this->order = ( $contribution['count'] && ! $contribution['is_error'] ) ? $contribution['values'][$contribution['id']] : false;

			if ( $this->order ) {
				// create participant payment
				$participant_payment = civicrm_api3( 'ParticipantPayment', 'create', [
					'participant_id' => $primary_participant_id,
					'contribution_id' => $contribution['id']
				] );

				$this->create_premium( $form_values, $config );
			}

		} catch ( CiviCRM_API3_Exception $e ) {
			$transdata['error'] = true;
			$transdata['note'] = $e->getMessage() . '<br><br><pre' . $e->getTraceAsString() . '</pre>';
		}
	}

	/**
	 * Create premium.
	 *
	 * @since 1.0
	 * @param array $order The order/contribution
	 * @param array $form_values The submitted form values
	 * @param array $config The processor config
	 */
	public function create_premium( $form_values, $config ) {

		global $transdata;

		if ( ! isset( $form_values['product_id'] ) ) return;

		if ( ! $this->order ) return;

		$params = [
			'product_id' => $form_values['product_id'],
			'contribution_id' => $this->order['id'],
			'quantity' => 1 // FIXME, can this be set via UI?
		];

		if ( isset( $transdata['data'][$config['product_id'] . '_option'] ) )
			$params['product_option'] = $transdata['data'][$config['product_id'] . '_option'];

		try {
			$premium = civicrm_api3( 'ContributionProduct', 'create', $params );
		} catch ( CiviCRM_API3_Exception $e ) {

		}

	}

	/**
	 * Track CiviDiscounts.
	 *
	 * @since 1.0
	 * @param array $order The order with it's line items
	 */
	public function track_cividiscounts( $order ) {

		if ( ! $order || ! isset( $order['id'] ) ) return;

		if ( ! isset( $this->plugin->cividiscount ) ) return;

		if ( empty( $this->plugin->processors->processors['participant']->discounts_used ) && ( empty( $this->plugin->processors->processors['participant']->price_field_refs  ) || empty( $this->plugin->processors->processors['participant']->price_field_option_refs ) ) )
			return;

		$price_field_refs = $this->plugin->processors->processors['participant']->price_field_refs;
		$price_field_option_refs = $this->plugin->processors->processors['participant']->price_field_option_refs;
		$discounts_used = $this->plugin->processors->processors['participant']->discounts_used;

		$price_field_option_refs = array_reduce( $price_field_option_refs, function( $refs, $ref ) {
			$refs[$ref['processor_id']] = $ref['field_id'];
			return $refs;
		}, [] );

		$participant_ids = array_reduce( $order['line_items'], function( $ids, $item ) {
			if ( $item['entity_table'] == 'civicrm_participant' ) {
				$ids[] = $item['entity_id'];
			}
			return $ids;
		}, [] );

		$participant_items = array_reduce( $order['line_items'], function( $items, $item ) {
			if ( $item['entity_table'] == 'civicrm_participant' ) {
				$items[$item['entity_id']] = $item;
			}
			return $items;
		}, [] );

		$participants = civicrm_api3( 'Participant', 'get', [
			'id' => [ 'IN' => $participant_ids ],
			'options' => [ 'limit' => 0 ]
		] );

		$participants = array_reduce( $participants['values'], function( $participants, $participant ) {
			$participants[] = $participant;
			return $participants;
		}, [] );

		$refs = array_merge( $price_field_refs, $price_field_option_refs );

		$transient = $this->plugin->transient->get();

		array_map( function( $processor_id, $field_id ) use ( $discounts_used, $transient, $order, $participants, $participant_items ) {

			$discount = isset( $discounts_used[$field_id] ) ? $discounts_used[$field_id] : false;

			if ( ! $discount ) return;

			$processor_id = $this->plugin->processors->processors['participant']->parse_processor_id( $processor_id );

			$event_id = $transient->events->$processor_id->event_id;

			$participant = array_filter( $participants, function( $participant ) use ( $event_id ) {
				return $participant['event_id'] == $event_id;
			} );

			$participant = array_pop( $participant );

			if ( ! $participant ) return;

			try {
				$discount_track = civicrm_api3( 'DiscountTrack', 'create', [
					'item_id' => $discount['id'],
					'contact_id' => $order['contact_id'],
					'contribution_id' => $order['id'],
					'entity_table' => $participant_items[$participant['id']]['entity_table'],
					'entity_id' => $participant['id'],
					'description' => [ $participant_items[$participant['id']]['label'] ]
				] );
			} catch ( CiviCRM_API3_Exception $e ) {
				Civi::log()->debug( 'Unable to track discount ' . $discount['code'] . ' for contribution id ' . $order['id'] );
			}

		}, array_keys( $refs ), $refs );

	}

	/**
	 * Preserve join date for current membership being processed.
	 *
	 * Background, implemented for new memberships considered as renewals to keep the join date from a
	 * previous membership of the same type.
	 *
	 * @since 0.4.4
	 * @param array $form Form configuration
	 */
	function preserve_membership_join_date( $form ) {
		
		$transient = $this->plugin->transient->get();
		
		if ( Caldera_Forms::get_processor_by_type( 'civicrm_membership', $form ) ) {
			foreach ( $form['processors'] as $id => $processor ) {
				if ( $processor['type'] == 'civicrm_membership' && isset( $processor['config']['preserve_join_date'] ) ) {
					// associated memberships
					$associated_memberships = $this->plugin->helper->get_organization_membership_types( $processor['config']['member_of_contact_id'] );

					// add expired and cancelled
					add_filter( 'cfc_current_membership_get_status', [ $this, 'add_expired_status' ], 10 );
					if ( isset( $processor['config']['is_membership_type'] ) ) {
						// get oldest membersip
						$oldest_membership = $this->plugin->helper->get_membership( 
							$transient->contacts->{$this->contact_link},
							$transient->memberships->$id->params['membership_type_id'],
							'ASC'
						);
					} else {
						$oldest_membership = $this->plugin->helper->get_membership( 
							$transient->contacts->{$this->contact_link},
							$membership_type = false,
							$sort = 'ASC'
						);
					}
					// remove filter
					remove_filter( 'cfc_current_membership_get_status', [ $this, 'add_expired_status' ], 10 );

					if ( $this->is_pay_later ) {
						// is pay later, filter membership status to pending 
						add_filter( 'cfc_current_membership_get_status', [ $this, 'set_pending_status' ], 10 );
						// get latest membership
						if ( $oldest_membership )
							$latest_membership = $this->plugin->helper->get_membership( 
								$transient->contacts->{$this->contact_link},
								$transient->memberships->$id->params['membership_type_id']
							);
						// remove filter
						remove_filter( 'cfc_current_membership_get_status', [ $this, 'set_pending_status' ], 10 );
					} else {
						if ( $oldest_membership )
							$latest_membership = $this->plugin->helper->get_membership( 
								$transient->contacts->{$this->contact_link},
								$transient->memberships->$id->params['membership_type_id'],
								$sort = 'DESC',
								$skip_status = true
							);
					}
					
					if ( $latest_membership && date( 'Y-m-d', strtotime( $oldest_membership['join_date'] ) ) < date( 'Y-m-d', strtotime( $latest_membership['join_date'] ) ) ) {
						// is latest/current membership one of associated?
						if ( $associated_memberships && in_array( $latest_membership['membership_type_id'], $associated_memberships ) ) {
							// set oldest join date
							$latest_membership['join_date'] = $oldest_membership['join_date'];
							// update membership
							$update_membership = civicrm_api3( 'Membership', 'create', $latest_membership );
						}
					}

					unset( $latest_membership, $oldest_membership, $associated_memberships );
				}
			}
		}

	}

	/**
	 * Set Pending status.
	 *
	 * @uses 'cfc_current_membership_get_status' filter
	 * @since 0.4.4
	 * @param array $statuses Membership statuses array
	 */
	public function set_pending_status( $statuses ) {
		return [ 'Pending' ];
	}

	/**
	 * Add expired and cancelled statuses.
	 *
	 * @uses 'cfc_current_membership_get_status' filter
	 * @since 0.4.4
	 * @param array $statuses Membership statuses array
	 */
	public function add_expired_status( $statuses ) {
		return array_merge( $statuses, [ 'Expired', 'Cancelled' ] );
	}

	/**
	 * Add payment processor hooks before pre process starts.
	 *
	 * @since 0.4.4
	 * 
	 * @param array $form Form config
	 * @param array $referrer URL referrer
	 * @param string $process_id The process id
	 */
	public function add_payment_processor_hooks( $form, $referrer, $process_id ) {

		// authorize single
		if ( Caldera_Forms::get_processor_by_type( 'auth-net-single', $form ) && ( Caldera_Forms_Field_Util::has_field_type( 'civicrm_country', $form ) || Caldera_Forms_Field_Util::has_field_type( 'civicrm_state', $form ) ) ) {

			/**
			 * Filter Authorize single payment customer data.
			 *
			 * @since 0.4.4
			 * 
			 * @param object $customer Customer data
			 * @param string $prefix processor slug prefix
			 * @param object $data_object Processor data object
			 * @return object $customer Customer data
			 */
			add_filter( 'cf_authorize_net_setup_customer', function( $customer, $prefix, $data_object ) use ( $form ) {

				foreach ( $data_object->get_fields() as $name => $field ) {
					if ( $name == $prefix . 'card_state' || $name == $prefix . 'card_country' ) {
						if ( ! empty( $field['config_field'] ) ) {
							// get field config
							$field_config = Caldera_Forms_Field_Util::get_field( $field['config_field'], $form );
							
							// replace country id with label
							if ( $field_config['type'] == 'civicrm_country' )
								$customer->country = $this->plugin->fields->field_objects['civicrm_country']->field_render_view( $customer->country, $field_config, $form );
							// replace state id with label
							if ( $field_config['type'] == 'civicrm_state' )
								$customer->state = $this->plugin->fields->field_objects['civicrm_state']->field_render_view( $customer->state, $field_config, $form );
						}
					}
				}
				return $customer;
			}, 10, 3 );
		}

		// stripe
		if ( Caldera_Forms::get_processor_by_type( 'stripe', $form ) ) {
			/**
			 * Process the Stripe balance transaction to get the fee and card detials.
			 *
			 * @since  0.4.4
			 * 
			 * @param array $return_charge Data about the successful charge
			 * @param array $transdata Data used to create transaction
			 * @param array $config The proessor config
			 * @param array $form The form config
			 */
			add_action( 'cf_stripe_post_successful_charge', function( $return_charge, $transdata, $config, $stripe_form ) {
				// stripe charge object from the successful payment
				$balance_transaction_id = $transdata['stripe']->balance_transaction;
				
				\Stripe\Stripe::setApiKey( $config['secret'] );
				$balance_transaction_object = \Stripe\BalanceTransaction::retrieve( $balance_transaction_id );
				
				$charge_metadata = [
					'fee_amount' => $balance_transaction_object->fee / 100,
					'card_type_id' => $this->get_option_by_label( $transdata['stripe']->source->brand ),
					'credit_card_type' => $transdata['stripe']->source->brand,
					'pan_truncation' => $transdata['stripe']->source->last4,
					'credit_card_exp_date' => [
						'M' => $transdata['stripe']->source->exp_month,
						'Y' => $transdata['stripe']->source->exp_year
					]
				];

				$this->charge_metadata = $charge_metadata;
			}, 10, 4 );
		}
	}

	/**
	 * Send email confirmation/receipt.
	 *
	 * @since 0.4.4
	 * 
	 * @param array $order The Order api result
	 * @param array $config Processor config
	 */
	public function maybe_send_confirmation( $order, $config ) {
		
		if ( ! $order ) return;

		if ( isset( $order['id'] ) && $config['is_email_receipt'] ) {
			try {
				civicrm_api3( 'Contribution', 'sendconfirmation', [ 'id' => $order['id'] ] );
			} catch ( CiviCRM_API3_Exception $e ) {
				Civi::log()->debug( 'Unable to send confirmation email for Contribution id ' . $order['id'] );
			}
		}
	}

	/**
	 * Order has participants.
	 *
	 * @since 1.0
	 * @param array $form_values The submitted values
	 * @return bool $has_participant
	 */
	public function has_participant( $form_values ) {

		if ( ! is_array( $form_values ) || ! isset( $form_values['line_items'] ) ) return false;

		$participant_line_items = array_filter( $form_values['line_items'], function( $line_item ) {
			return $line_item['line_item'][0]['entity_table'] === 'civicrm_participant';
		} );

		return ! empty( $participant_line_items ) ? true : false;

	}

	/**
	 * Get OptionValue by label.
	 *
	 * @since 0.4.4
	 * 
	 * @param string $label
	 * @return mixed $value
	 */
	public function get_option_by_label( $label ) {
		try {
			$option_value = civicrm_api3( 'OptionValue', 'getsingle', [
				'label' => $label,
			] );
			
		} catch ( CiviCRM_API3_Exception $e ) {
			// ignore	
		}

		if ( isset( $option_value ) && is_array( $option_value ) )
			return $option_value['value'];
		return null;
	}
}
