<?php
/**
 * "The contents of this file are subject to the Mozilla Public License
 *  Version 1.1 (the "License"); you may not use this file except in
 *  compliance with the License. You may obtain a copy of the License at
 *  http://www.mozilla.org/MPL/

 *  Software distributed under the License is distributed on an "AS IS"
 *  basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See the
 *  License for the specific language governing rights and limitations
 *  under the License.

 *  The Original Code is OpenVBX, released June 15, 2010.

 *  The Initial Developer of the Original Code is Twilio Inc.
 *  Portions created by Twilio Inc. are Copyright (C) 2010.
 *  All Rights Reserved.

 * Contributor(s):
 **/

class VBX_SettingsException extends Exception {}

class VBX_Settings extends Model
{
	protected $settings_table = 'settings';
	protected $tenants_table = 'tenants';

	public $setting_options = array(
								'twilio_sid',
								'twilio_token',
								'application_sid',
								'twilio_endpoint',
								'from_email',
								'recording_host',
								'theme',
								'transcriptions',
								'voice',
								'voice_language',
								'numbers_country',
								'gravatars'
							);

	protected $settings_params = array(
								'name',
								'value',
								'tenant_id'
							);

	protected $tenants_params = array(
								'active',
								'name',
								'url_prefix',
								'type'
							);

	private $cache_key;

	const CACHE_TIME_SEC = 1;
	
	const CLIENT_TOKEN_TIMEOUT = 28800; // 8 hours
	
	const AUTH_TYPE_PARENT = 0;
	const AUTH_TYPE_FULL = 1; // @note currently not used, this is for future expansion
	const AUTH_TYPE_SUBACCOUNT = 2;
	const AUTH_TYPE_CONNECT = 3;

	function __construct()
	{
		parent::__construct();
		$this->cache_key = 'settings';
	}

	function get_all_tenants()
	{
		$ci =& get_instance();

		$tenants = $ci->db
			 ->from($this->tenants_table)
			 ->where('name !=', 'default')
			 ->get()->result();

		// we won't use cache here, but we may as well contribute to it
		if (!empty($tenants))
		{
			foreach ($tenants as $tenant)
			{
				$ci->cache->set($tenant->id, $tenant, 'tenants');
			}
		}

		return $tenants;
	}

	function get_tenant($url_prefix)
	{
		$ci =& get_instance();

		// tenant cache is by tenant_id
		// search cache for a tenant to return
		if ($cache = $ci->cache->group('tenants'))
		{
			foreach ($cache as $cached_tenant)
			{
				if ($cached_tenant->url_prefix == $url_prefix)
				{
					return $cached_tenant;
				}
			}
		}

		$query = $ci->db
			 ->from($this->tenants_table)
			 ->where('url_prefix', strtolower($url_prefix))
			 ->get();

		if ($query) 
		{
			$tenant = $query->result();
			if(!empty($tenant[0]))
			{
				$ci->cache->set($tenant[0]->id, $tenant[0], 'tenants');
				return $tenant[0];
			}
		}
		
		return false;
	}

	// @deprecated? can't find it in use
	function get_tenant_by_name($name)
	{
		$ci =& get_instance();

		$tenant = $ci->db
			 ->from('tenants as i')
			 ->where('i.name', $name)
			 ->get()->result();

		if(!empty($tenant[0]))
			return $tenant[0];

		return false;
	}

	function get_tenant_by_id($id)
	{
		$ci =& get_instance();

		if ($cache = $ci->cache->get($id, 'tenants'))
		{
			return $cache;
		}

		$tenant = $ci->db
			 ->from($this->tenants_table)
			 ->where('id', $id)
			 ->get()->result();

		if(!empty($tenant[0]))
		{
			$ci->cache->set($tenant[0]->id, $tenant[0], 'tenants');
			return $tenant[0];
		}

		return false;
	}

	/**
	 * Add a new tenant
	 *
	 * @throws VBX_SettingsException
	 * @param string $name 
	 * @param string $url_prefix 
	 * @param string $local_prefix 
	 * @return int $tenant_id
	 */
	function tenant($name, $url_prefix, $local_prefix)
	{
		$ci =& get_instance();

		$tenant = $this->get_tenant($url_prefix);
		$errors = array();

		if(strlen($url_prefix) > 32)
		{
			$errors[] = "Tenant name exceeds 32 character limit";
		}

		if(preg_match('/[^0-9A-Za-z_-]/', $name) > 0)
		{
			$errors[] = "Tenant name contains invalid characters. ".
						"Allowed characters: alphanumeric, dashes, and underscores.";
		}

		if(!empty($errors))
		{
			throw new VBX_SettingsException(implode(',', $errors));
		}

		if($tenant === false)
		{
			$ci->db
				->set('name', $name)
				->set('url_prefix', $url_prefix)
				->set('local_prefix', $local_prefix)
				->insert($this->tenants_table);
			$tenant_id = $ci->db->insert_id();
			if(!$tenant_id)
			{
				throw new VBX_SettingsException('Tenant failed to create');
			}
			
			$ci->cache->flush('tenants');
			return $tenant_id;
		}

		throw new VBX_SettingsException('Tenant by this name or url already exists');
	}

	function update_tenant($tenant)
	{
		$ci =& get_instance();

		$errors = array();
		if(!(!empty($tenant)
			 && isset($tenant['id'])
			 && intval($tenant['id']) > 0
			 && $this->get_tenant_by_id($tenant['id']) !== false))
		{
			throw new VBX_SettingsException('Can not update tenant, malformed update request');
		}

		if(isset($tenant['url_prefix'])
		   && strlen($tenant['url_prefix']) > 32)
		{
			$errors[] = "Tenant name exceeds 32 character limit";
		}

		if(isset($tenant['name'])
		   && preg_match('/[^0-9A-Za-z_-]/', $name) > 0)
		{
			$errors[] = "Tenant name contains invalid characters. ".
						"Allowed characters: alphanumeric, dashes, and underscores.";
		}

		foreach($this->tenants_params as $param)
		{
			if(isset($tenant[$param]))
			{
				$ci->db
					->set($param, $tenant[$param]);
			}
		}

		$ci->cache->flush('tenants');
		return $ci->db
			->where('id', $tenant['id'])
			->update($this->tenants_table);
	}

	function add($name, $value, $tenant_id)
	{
		$ci =& get_instance();

		if($this->get_tenant_by_id($tenant_id) === false)
		{
			return false;
		}

		if($this->get($name, $tenant_id) !== false) {
			$ci->db
				->set('value', $value)
				->where('name', $name)
				->where('tenant_id', $tenant_id)
				->update($this->settings_table);
		} else {
			$ci->db
				->set('name', $name)
				->set('value', $value)
				->set('tenant_id', $tenant_id)
				->insert($this->settings_table);
		}

		$ci->cache->set($name, $value, 'settings-'.$tenant_id);
		
		return $ci->db
			->insert_id();
	}

	function set($name, $value, $tenant_id)
	{
		$ci =& get_instance();
		
		if($this->get($name, $tenant_id) === false)
		{
			return false;
		}
		
		$ci->db
			->set('value', $value)
			->where('name', $name)
			->where('tenant_id', $tenant_id)
			->update($this->settings_table);

		$ci->cache->delete($name, 'settings-'.$tenant_id);

		return ($ci->db
				->affected_rows() > 0? true : false);
	}

	function get($name, $tenant_id)
	{
		$cache_key = $name;
		
		$ci =& get_instance();
		if ($cache = $ci->cache->get($name, 'settings-'.$tenant_id)) {
			return $cache->value;
		}

		$ci =& get_instance();
		$query = $ci->db
			->select()
			->from($this->settings_table)
			->where(array(
				'name' => $name, 
				'tenant_id' => intval($tenant_id)
			))
			->get();
		
		if ($query) 
		{
			$result = $query->result();

			if(!empty($result[0]))
			{
				$ci->cache->set($name, $result[0], 'settings-'.$tenant_id);
				return $result[0]->value;
			}
		}
		
		return false;
	}
	
	function delete($name, $tenant_id) {
		$ci =& get_instance();
		
		if($this->get($name, $tenant_id) === false)
		{
			return false;
		}
		
		$query = $ci->db
					->where(array(
						'name' => $name, 
						'tenant_id' => $tenant_id
					))
					->delete($this->settings_table);
		
		$ci->cache->delete($name, 'settings-'.$tenant_id);

		return ($ci->db->affected_rows() > 0 ? true : false);
	}

	function get_all_by_tenant_id($tenant_id)
	{
		$ci =& get_instance();

		$known_settings = array_keys($ci->cache->group('settings-'.$tenant_id));

		$result = $ci->db
			->from($this->settings_table)
			->where_not_in('name', $known_settings)
			->where('tenant_id', $tenant_id)
			->get()->result();

		foreach ($result as $item)
		{
			$ci->cache->set($item->name, $item, 'settings-'.$tenant_id);
		}

		return $ci->cache->group('settings-'.$tenant_id);
	}

}
