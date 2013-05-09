<?php

/**
 * Rah_blobin plugin for Textpattern CMS.
 *
 * @author  Jukka Svahn
 * @date    2013-
 * @license GNU GPLv2
 * @link    https://github.com/gocom/rah_blobin
 *
 * Copyright (C) 2013 Jukka Svahn http://rahforum.biz
 * Licensed under GNU General Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

class rah_blobin
{
	/**
	 * Parsed manifest file.
	 *
	 * @var stdClass
	 */

	protected $manifest;

	/**
	 * An array of existing plugins.
	 *
	 * @var array
	 */

	protected $plugins;

	/**
	 * Uninstallation queue.
	 *
	 * @var array
	 */

	protected $uninstall;

	/**
	 * Current directory.
	 *
	 * @var string
	 */

	protected $dir;

	/**
	 * Constructor.
	 */

	public function __construct()
	{
		add_privs('rah_blobin_sync', '1');
		add_privs('prefs.rah_blobin', '1');
		register_callback(array($this, 'install'), 'plugin_lifecycle.rah_blobin', 'installed');
		register_callback(array($this, 'uninstall'), 'plugin_lifecycle.rah_blobin', 'deleted');

		$this->dir = txpath;

		if (!defined('rah_blobin_plugins_dir'))
		{
			define('rah_blobin_plugins_dir', $this->path(get_pref('rah_blobin_path')));
		}

		if (rah_blobin_plugins_dir)
		{
			register_callback(array($this, 'endpoint'), 'textpattern');
			$this->sync();
		}
	}

	/**
	 * Installer.
	 */

	public function install()
	{
		$position = 250;

		foreach (
			array(
				'path'    => array('text_input', '../rah_blobin'),
				'key'     => array('text_input', md5(uniqid(mt_rand(), true))),
				'sync'    => array('rah_blobin_sync', 0),
			) as $name => $val
		)
		{
			$n =  'rah_blobin_'.$name;

			if (get_pref($n, false) === false)
			{
				set_pref($n, $val[1], 'rah_blobin', PREF_PLUGIN, $val[0], $position);
			}

			$position++;
		}
	}

	/**
	 * Uninstaller.
	 */

	public function uninstall()
	{
		safe_delete('txp_prefs', "name like 'rah\_blobin\_%'");
	}

	/**
	 * Public callback hook endpoint.
	 *
	 * Can be used to manually invoke plugin import progress.
	 */

	public function endpoint()
	{
		extract(gpsa(array(
			'rah_blobin',
		)));

		if (get_pref('rah_blobin_key') && get_pref('rah_blobin_key') === $rah_blobin)
		{
			$this->import();
			die;
		}
	}

	/**
	 * Private callback hook endpoint.
	 */

	public function sync()
	{
		if (txpinterface === 'admin' && gps('rah_blobin_sync') && has_privs('rah_blobin_sync') && bouncer('sync', array('sync' => true)))
		{
			$this->import();
		}
	}

	/**
	 * Gets an array of existing plugins.
	 *
	 * Keys are the plugins, values contain the version numbers.
	 * Version number will be FALSE if the plugin wasn't
	 * installed by rah_blobin.
	 *
	 * @return array
	 */

	protected function existing()
	{
		$rs = safe_rows_start(
			'name, version, code',
			'txp_plugin',
			'1 = 1'
		);

		$out = array();

		while ($a = nextRow($rs))
		{
			if ($a['name'] != 'rah_blobin' && strpos($a['code'], '/* Generated by rah_blobin */') !== 0)
			{
				$a['version'] = false;
			}

			$out[$a['name']] = $a['version'];
		}

		return $out;
	}

	/**
	 * Imports plugin manifest files to the database.
	 */

	public function import()
	{
		$this->uninstall = $this->plugins = $this->existing();

		$iterator = new RecursiveDirectoryIterator(rah_blobin_plugins_dir);
		$iterator = new RecursiveIteratorIterator($iterator);

		foreach ($iterator as $file)
		{
			$file = (string) $file;

			if (basename($file) !== 'manifest.json')
			{
				continue;
			}

			$this->dir = dirname($file);
			$this->manifest = json_decode(file_get_contents($file));
			$code = $this->template();
			$flags = (int) $this->manifest->flags;

			unset($this->uninstall[$this->manifest->name]);

			if (isset($this->plugins[$this->manifest->name]))
			{
				$code = $this->template();
				$help = $this->help();

				if ($this->plugins[$this->manifest->name] === false)
				{
					continue;
				}

				if (!empty($this->manifest->uninstall))
				{
					$this->uninstall[$this->manifest->name] = true;

					if ($flags & PLUGIN_LIFECYCLE_NOTIFY)
					{
						load_plugin($this->manifest->name, true);
						callback_event('plugin_lifecycle.'.$this->manifest->name, 'disabled');
						callback_event('plugin_lifecycle.'.$this->manifest->name, 'deleted');
					}

					continue;
				}

				if ((string) $this->manifest->version === (string) $this->plugins[$this->manifest->name])
				{
					continue;
				}

				$rs = safe_update(
					'txp_plugin',
					"author = '".doSlash($this->manifest->author)."',
					author_uri = '".doSlash($this->manifest->author_uri)."',
					version = '".doSlash($this->manifest->version)."',
					description = '".doSlash($this->manifest->description)."',
					help = '".doSlash($help)."',
					code = '".doSlash($code)."',
					code_restore = '".doSlash($code)."',
					code_md5 = '".doSlash(md5($code))."',
					type = '".doSlash($this->manifest->type)."',
					load_order = '".doSlash($this->manifest->order)."',
					flags = {$flags}",
					"name = '".doSlash($this->manifest->name)."'"
				);
			}
			else
			{
				if (!empty($this->manifest->uninstall))
				{
					continue;
				}

				$code = $this->template();
				$help = $this->help();

				$rs = safe_insert(
					'txp_plugin',
					"name = '".doSlash($this->manifest->name)."',
					status = 1,
					author = '".doSlash($this->manifest->author)."',
					author_uri = '".doSlash($this->manifest->author_uri)."',
					version = '".doSlash($this->manifest->version)."',
					description = '".doSlash($this->manifest->description)."',
					help = '".doSlash($help)."',
					code = '".doSlash($code)."',
					code_restore = '".doSlash($code)."',
					code_md5 = '".doSlash(md5($code))."',
					type = '".doSlash($this->manifest->type)."',
					load_order = '".doSlash($this->manifest->order)."',
					flags = {$flags}"
				);
			}

			if ($rs)
			{
				$textpack = $this->textpack();

				if ($textpack)
				{
					$textpack = '#@owner '.$this->manifest->name.n.$textpack;
					install_textpack($textpack, false);
				}

				if ($flags & PLUGIN_LIFECYCLE_NOTIFY)
				{
					load_plugin($this->manifest->name, true);
					callback_event('plugin_lifecycle.'.$this->manifest->name, 'installed');
					callback_event('plugin_lifecycle.'.$this->manifest->name, 'enabled');
				}
			}
		}

		// Delete removed.

		foreach ($this->uninstall as $name => $version)
		{
			if ($version !== false)
			{
				safe_delete('txp_plugin', "name = '".doSlash($name)."'");
				safe_delete('txp_lang', "owner = '".doSlash($name)."'");
			}
		}
	}

	/**
	 * Plugin code template.
	 *
	 * Generates PHP source code that either imports
	 * the first .php file in the directory or the files
	 * specified with the 'file' attribute of 'code'.
	 *
	 * @return string
	 */

	protected function template()
	{
		$files = $out = array();
		$out[] = '/* Generated by rah_blobin */';
		$out[] = '/* '.safe_strftime('%Y-%m-%d %H:%M:%S').' */';
		$out[] = "require_plugin('rah_blobin');";

		if (isset($this->manifest->code->file))
		{
			$files = array_map(array($this, 'path'), (array) $this->manifest->code->file);
		}
		else
		{
			$files = (array) glob($this->dir . '/*.php');
		}

		foreach ($files as $path)
		{
			if (strpos($path, rah_blobin_plugins_dir) === 0)
			{
				$path = addslashes(ltrim(substr($path, strlen(rah_blobin_plugins_dir)), '\\/'));
				$out[] = "include rah_blobin_plugins_dir . '/{$path}';";
			}
			else
			{
				$out[] = "include '".addslashes($path)."';";
			}
		}

		return implode(n, $out);
	}

	/**
	 * Gets Textpacks.
	 *
	 * @return string
	 */

	protected function textpack()
	{
		if (!file_exists($this->dir . '/textpacks') || !is_dir($this->dir . '/textpacks'))
		{
			return '';
		}

		$out = array();

		foreach ((array) glob($this->dir . '/textpacks/*.textpack', GLOB_NOSORT) as $file)
		{
			$file = file_get_contents($file);

			if (strpos($file, '#@language') === false)
			{
				array_unshift($out, $file);
				continue;
			}

			$out[] =  $file;
		}

		return implode(n, $out);
	}

	/**
	 * Processes help files.
	 *
	 * @return string
	 */

	protected function help()
	{
		$out = array();

		if (isset($this->manifest->help->file))
		{
			foreach ((array) $this->manifest->help->file as $file)
			{
				$out[] = file_get_contents($this->path($file));
			}
		}
		else if (isset($this->manifest->help))
		{
			$out[] = $this->manifest->help;
		}

		if ($out)
		{
			$textile = new Textpattern_Textile_Parser();
			return $textile->TextileRestricted(implode(n, $out), 0, 0);
		}

		return '';
	}

	/**
	 * Forms absolute file path.
	 *
	 * @param  string $path
	 * @return string
	 */

	protected function path($path)
	{
		if (strpos($path, './') === 0)
		{
			return $this->dir . '/' . substr($path, 2);
		}

		if (strpos($path, '../') === 0)
		{
			return dirname($this->dir) . '/' . substr($path, 3);
		}

		return $path;
	}
}

/**
 * Option to sync.
 *
 * @return bool
 */

function rah_blobin_sync()
{
	global $event, $step;

	if (has_privs('rah_blobin_sync'))
	{
		return href(gTxt('rah_blobin_sync_now'), array(
			'event'           => $event,
			'step'            => $step,
			'rah_blobin_sync' => 1,
			'_txp_token'      => form_token(),
		), array(
			'class' => 'navlink',
		));
	}
	else
	{
		return span(gTxt('rah_blobin_sync_now'), array(
			'class' => 'navlink-disabled',
		));
	}
}

new rah_blobin();