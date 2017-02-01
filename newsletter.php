<?php
/*
* 2007-2016 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2016 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_'))
	exit;

class Newsletter extends Module
{
	private $post_errors = array();
	private $html = '';

	public function __construct()
	{
		$this->name = 'newsletter';
		$this->tab = 'administration';
		$this->version = '2.5.3';
		$this->author = 'PrestaShop';
		$this->need_instance = 0;

		$this->bootstrap = true;
		parent::__construct();

		$this->displayName = $this->l('Newsletter');
		$this->description = $this->l('Generates a .CSV file for mass mailings');

		if ($this->id)
		{
			$this->file = 'export_'.Configuration::get('PS_NEWSLETTER_RAND').'.csv';
			$this->post_valid = array();

			// Getting data...
			$countries = Country::getCountries($this->context->language->id);

			// ...formatting array
			$countries_list = array($this->l('All countries'));
			foreach ($countries as $country)
				$countries_list[$country['id_country']] = $country['name'];

			// And filling fields to show !
			$this->fields_export = array(
				'COUNTRY' => array(
					'title' => $this->l('Customers\' country'),
					'desc' => $this->l('Filter customers\' country.'),
					'type' => 'select',
					'value' => $countries_list,
					'value_default' => 0
				),
				'SUSCRIBERS' => array(
					'title' => $this->l('Newsletter subscribers'),
					'desc' => $this->l('Filter newsletter subscribers.'),
					'type' => 'select',
					'value' => array(
						0 => $this->l('All customers'),
						2 => $this->l('Subscribers'),
						1 => $this->l('Non-subscribers')
					),
					'value_default' => 2
				),
				'OPTIN' => array(
					'title' => $this->l('Opt-in subscribers'),
					'desc' => $this->l('Filter opt-in subscribers.'),
					'type' => 'select',
					'value' => array(
						0 => $this->l('All customers'),
						2 => $this->l('Subscribers'),
						1 => $this->l('Non-subscribers')
					),
					'value_default' => 0
				),
			);
		}
	}

	public function install()
	{
		return (parent::install() && Configuration::updateValue('PS_NEWSLETTER_RAND', rand().rand()));
	}

	private function postProcess()
	{
		$result = false;
		if (Tools::isSubmit('submitExport') && $action = Tools::getValue('action'))
		{
			$result = $this->getCustomers();
		}

		if ($result)
		{
			if (!$nb = count($result))
				$this->html .= $this->displayError($this->l('No customers found with these filters!'));
			elseif ($fd = @fopen(dirname(__FILE__).'/'.strval(preg_replace('#\.{2,}#', '.', Tools::getValue('action'))).'_'.$this->file, 'w'))
			{
				$header = array('id', 'shop_name', 'gender', 'lastname', 'firstname', 'email', 'subscribed', 'subscribed_on');
				$array_to_export = array_merge(array($header), $result);
				foreach ($array_to_export as $tab)
					$this->myFputCsv($fd, $tab);
				fclose($fd);
				$this->html .= $this->displayConfirmation(
					sprintf($this->l('The .CSV file has been successfully exported: %d customers found.'), $nb).'<br />
				<a href="../modules/newsletter/'.Tools::safeOutput(strval(Tools::getValue('action'))).'_'.$this->file.'">
				<b>'.$this->l('Download the file').' '.$this->file.'</b>
				</a>
				<br />
				<ol style="margin-top: 10px;">
					<li style="color: red;">'.
					$this->l('WARNING: When opening this .csv file with Excel, choose UTF-8 encoding to avoid strange characters.').
					'</li>
				</ol>');
			}
			else
				$this->html .= $this->displayError($this->l('Error: Write access limited').' '.dirname(__FILE__).'/'.strval(Tools::getValue('action')).'_'.$this->file.' !');
		}
	}

	private function getCustomers()
	{
		// Get the value for subscriber's status (1=with account, 2=without account, 0=both, 3=no subscribtion)
		$who = (int)Tools::getValue('SUSCRIBERS');

		// get optin 0 for all 1 no optin 2 with optin
		$optin = (int)Tools::getValue('OPTIN');

		$country = (int)Tools::getValue('COUNTRY');

		if (Context::getContext()->cookie->shopContext)
			$id_shop = (int)Context::getContext()->shop->id;

		$customers = array();
		if ($who == 1 || $who == 0 || $who == 3)
		{
			$dbquery = new DbQuery();
			$dbquery->select('c.`id_customer` AS `id`, s.`name` AS `shop_name`, gl.`name` AS `gender`, c.`lastname`, c.`firstname`, c.`email`, c.`newsletter` AS `subscribed`, c.`newsletter_date_add`');
			$dbquery->from('customer', 'c');
			$dbquery->leftJoin('shop', 's', 's.id_shop = c.id_shop');
			$dbquery->leftJoin('gender', 'g', 'g.id_gender = c.id_gender');
			$dbquery->leftJoin('gender_lang', 'gl', 'g.id_gender = gl.id_gender AND gl.id_lang = '.$this->context->employee->id_lang);
			$dbquery->where('c.`newsletter` = '.($who == 3 ? 0 : 1));
			if ($optin)
				$dbquery->where('c.`optin` = '.$optin == 1 ? 0 : 1);
			if ($country)
				$dbquery->where('(SELECT COUNT(a.`id_address`) as nb_country
													FROM `'._DB_PREFIX_.'address` a
													WHERE a.deleted = 0
													AND a.`id_customer` = c.`id_customer`
													AND a.`id_country` = '.$country.') >= 1');
			if ($id_shop)
				$dbquery->where('c.`id_shop` = '.$id_shop);
			$customers = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($dbquery->build());
		}

		$non_customers = array();
		if (($who == 0 || $who == 2) && !$optin && !$country)
		{
			$dbquery = new DbQuery();
			$dbquery->select('CONCAT(\'N\', n.`id`) AS `id`, s.`name` AS `shop_name`, NULL AS `gender`, NULL AS `lastname`, NULL AS `firstname`, n.`email`, n.`active` AS `subscribed`, n.`newsletter_date_add`');
			$dbquery->from('newsletter', 'n');
			$dbquery->leftJoin('shop', 's', 's.id_shop = n.id_shop');
			$dbquery->where('n.`active` = 1');
			if ($id_shop)
				$dbquery->where('n.`id_shop` = '.$id_shop);
			$non_customers = Db::getInstance()->executeS($dbquery->build());
		}

		$subscribers = array_merge($customers, $non_customers);

		return $subscribers;
	}

	private function getBlockNewsletter()
	{
		$rq_sql = 'SELECT `id`, `email`, `newsletter_date_add`, `ip_registration_newsletter`
		FROM `'._DB_PREFIX_.'newsletter`
		WHERE `active` = 1';

		if (Context::getContext()->cookie->shopContext)
			$rq_sql .= ' AND `id_shop` = '.(int)Context::getContext()->shop->id;

		$rq = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($rq_sql);

		$header = array('id_customer', 'email', 'newsletter_date_add', 'ip_address', 'http_referer');
		$result = (is_array($rq) ? array_merge(array($header), $rq) : $header);

		return $result;
	}

	private function myFputCsv($fd, $array)
	{
		$line = implode(';', $array);
		$line .= "\n";
		if (!fwrite($fd, $line, 4096))
			$this->post_errors[] = $this->l('Error: cannot write').' '.dirname(__FILE__).'/'.$this->file.' !';
	}

	public function getContent()
	{
		$this->html .= '';

		if (!empty($_POST))
			$this->postProcess();
		$this->html .= $this->renderForm();

		return $this->html;
	}

	public function renderForm()
	{
		// Getting data...
		$countries = Country::getCountries($this->context->language->id);

		// ...formatting array
		$countries_list = array(array('id' => 0, 'name' => $this->l('All countries')));
		foreach ($countries as $country)
			$countries_list[] = array('id' => $country['id_country'], 'name' => $country['name']);

		// And filling fields to show !
		$this->fields_export = array(
			'COUNTRY' => array(
				'title' => $this->l('Customers\' country'),
				'desc' => $this->l('Filter customers\' country.'),
				'type' => 'select',
				'value' => $countries_list,
				'value_default' => 0
			),
			'SUSCRIBERS' => array(
				'title' => $this->l('Newsletter subscribers'),
				'desc' => $this->l('Filter newsletter subscribers.'),
				'type' => 'select',
				'value' => array(
					0 => $this->l('All Subscribers'),
					1 => $this->l('Subscribers with account'),
					2 => $this->l('Subscribers without account'),
					3 => $this->l('Non-subscribers')
				),
				'value_default' => 0
			),
			'OPTIN' => array(
				'title' => $this->l('Opt-in subscribers'),
				'desc' => $this->l('Filter opt-in subscribers.'),
				'type' => 'select',
				'value' => array(
					0 => $this->l('All customers'),
					2 => $this->l('Subscribers'),
					1 => $this->l('Non-subscribers')
				),
				'value_default' => 0
			),
		);

		$fields_form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Export customers'),
					'icon' => 'icon-envelope'
				),
				'input' => array(
					array(
						'type' => 'select',
						'label' => $this->l('Customers\' country'),
						'desc' => $this->l('Filter customers\' country.'),
						'name' => 'COUNTRY',
						'required' => false,
						'default_value' => (int)$this->context->country->id,
						'options' => array(
							'query' => $countries_list,
							'id' => 'id',
							'name' => 'name',
						)
					),
					array(
						'type' => 'select',
						'label' => $this->l('Newsletter subscribers'),
						'desc' => $this->l('Filter newsletter subscribers.'),
						'name' => 'SUSCRIBERS',
						'required' => false,
						'default_value' => (int)$this->context->country->id,
						'options' => array(
							'query' => array(
								array('id' => 0, 'name' => $this->l('All Subscribers')),
								array('id' => 1, 'name' => $this->l('Subscribers with account')),
								array('id' => 2, 'name' => $this->l('Subscribers without account')),
								array('id' => 3, 'name' => $this->l('Non-subscribers'))
							),
							'id' => 'id',
							'name' => 'name',
						)
					),
					array(
						'type' => 'select',
						'label' => $this->l('Opt-in subscribers'),
						'desc' => $this->l('Filter opt-in subscribers.'),
						'name' => 'OPTIN',
						'required' => false,
						'default_value' => (int)$this->context->country->id,
						'options' => array(
							'query' => array(
								array('id' => 0, 'name' => $this->l('All customers')),
								array('id' => 2, 'name' => $this->l('Subscribers')),
								array('id' => 1, 'name' => $this->l('Non-subscribers'))
							),
							'id' => 'id',
							'name' => 'name',
						)
					),
					array(
						'type' => 'hidden',
						'name' => 'action',
					)
				),
				'submit' => array(
					'title' => $this->l('Export .CSV file'),
					'class' => 'btn btn-default pull-right',
					'name' => 'submitExport',
				)
			),
		);

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$helper->id = (int)Tools::getValue('id_carrier');
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'btnSubmit';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFieldsValues(),
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id
		);

		return $helper->generateForm(array($fields_form));
	}

	public function getConfigFieldsValues()
	{
		return array(
			'COUNTRY' => Tools::getValue('COUNTRY'),
			'SUSCRIBERS' => Tools::getValue('SUSCRIBERS'),
			'OPTIN' => Tools::getValue('OPTIN'),
			'action' => 'customers',
		);
	}

}
