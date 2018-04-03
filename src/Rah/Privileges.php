<?php

/*
 * rah_privileges - Configure admin-side privileges
 * https://github.com/gocom/rah_privileges
 *
 * Copyright (C) 2015 Jukka Svahn
 *
 * This file is part of rah_privileges.
 *
 * rah_privileges is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, version 2.
 *
 * rah_privileges is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with hpw_admincss. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * The plugin class.
 *
 * @internal
 */

class Rah_Privileges
{
    /**
     * Constructor.
     */

    public function __construct()
    {
        global $event;

        add_privs('prefs.rah_privs', '1');

        register_callback(array($this, 'enabled'), 'plugin_lifecycle.rah_privileges', 'enabled');
        register_callback(array($this, 'uninstall'), 'plugin_lifecycle.rah_privileges', 'deleted');
        register_callback(array($this, 'addLocalization'), 'prefs', '', 1);

        if ($event === 'prefs') {

            // Get user group language strings not in plugin namespace
            $ui_lang = get_pref('language_ui', TEXTPATTERN_DEFAULT_LANG);
            $langObject = \Txp::get('\Textpattern\L10n\Lang');
            $strings = $langObject->extract($ui_lang, 'admin'); // Get from 'admin' group
            $langObject->setPack($strings, true); // Append (=true) to internal loaded strings

            register_callback(array($this, 'injectCss'), 'admin_side', 'head_end');
            $this->syncPrefs();
        }

        $this->mergePrivileges();
    }


    /**
     * Flush priv language strings on enable.
     */

    public function enabled()
    {
        safe_delete('txp_lang', "name like 'rah\_privileges\_%'");
    }


    /**
     * Uninstaller.
     */

    public function uninstall()
    {
        safe_delete('txp_prefs', "name like 'rah\_privileges\_%'");
        safe_delete('txp_lang', "owner = 'rah_privileges'");
    }


    /**
     * CSS for Prefs pane.
     */

    public function injectCss()
    {
        // rah_privs stylesheets
        echo '<style>
    .txp-tabs-vertical-group .txp-form-field-label, .txp-tabs-vertical-group .txp-form-field-value { flex: 1 1 100%; }
    #prefs_group_rah_privs .txp-form-field-label label { font-weight: bold; }
    #prefs_group_rah_privs .txp-form-field-value span { margin-right: 1.75em; white-space: nowrap; }
</style>';
    }


    /**
     * Syncs preference fields.
     *
     * Creates preference keys for each permission resource. This
     * is how we get the fields to show up in the interface.
     */

    public function syncPrefs()
    {
        global $textarray, $txp_permissions;

        $active = array();

        $ui_lang = get_pref('language_ui', TEXTPATTERN_DEFAULT_LANG);

        // Create a preferences string for every privilege that exists.
        foreach ($txp_permissions as $resource => $privs) {
            $name = 'rah_privileges_' . md5($resource);
            $textarray[$name] = $resource;


            if(gTxt($name) == $name) {
                safe_upsert('txp_lang',
                    "event   = 'admin',
                     owner   = 'rah_privileges',
                     lang    = '".$ui_lang."',
                     data    = '".$resource."',
                     lastmod = now()",
                    "name = '".$name."'"
                );
            }

            // Add panel name infront of the list.
            $privs = do_list($privs);
            array_unshift($privs, $resource);
            $privs = implode(', ', $privs);

            if (get_pref($name, false) === false) {
                set_pref($name, $privs, 'rah_privs', PREF_PLUGIN, 'rah_privileges_input', 80);
            }

            $active[] = $name;
        }

        // Remove privileges that no longer exist.

        if ($active) {
            $active = implode(',', quote_list((array) $active));

            safe_delete(
                'txp_prefs',
                "name like 'rah\_privileges\_%' and name not in({$active})"
            );
            safe_delete(
                'txp_lang',
                "name like 'rah\_privileges\_%' and name not in({$active})"
            );
        }
    }

    /**
     * Add panel titles into the translation array as pref labels.
     */

    public function addLocalization() {
        global $textarray;

        $resources = array();

        foreach (areas() as $area => $events) {
            foreach ($events as $title => $resource) {
                $name = 'rah_privileges_' . md5($resource);
                $textarray[$name] = $title;
            }
        }

        // Update field sorting index.
        foreach ($textarray as $name => $string) {
            if (strpos($name, 'rah_privileges_') === 0) {
                $resources[$name] = $string;
            }
        }

        $index = 1;
        asort($resources);

        foreach ($resources as $name => $resource) {
            update_pref($name, null, null, null, null, $index++);
        }
    }

    /**
     * Merges permissions table with our overwrites.
     */

    public function mergePrivileges()
    {
        global $prefs, $txp_permissions, $event;

        foreach ($prefs as $name => $value) {
            if (strpos($name, 'rah_privileges_') !== 0) {
                continue;
            }

            $groups = do_list($value);
            $resource = array_shift($groups);
            $groups = implode(',', $groups);

            if ($event === 'prefs' && strpos($resource, 'prefs') === 0) {
                continue;
            }

            if (!$groups) {
                $txp_permissions[$resource] = null;
            } else {
                $txp_permissions[$resource] = $groups;
            }
        }
    }
}

/**
 * Renders input for setting privilege settings.
 *
 * @return string HTML widget
 */

function rah_privileges_input($name, $value)
{
    global $txp_permissions, $plugin_areas;

    $field = $name . '[]';
    $levels = get_groups();
    $groups = do_list($value);
    $resource = array_shift($groups);
    $out = array();

    unset($levels[0]);
    $out[] = hInput($field, $resource);

    foreach ($levels as $group => $label) {
        $id = $name . '_' . intval($group);
        $checked = in_array($group, $groups);

        $out[] = tag(
            checkbox(
                $field,
                $group,
                $checked,
                '',
                $id
            ) . ' ' .

            tag(gTxt($label), 'label', array('for' => $id)),

            'span'

        ). ' ';

    }

    return implode('', $out);
}

new Rah_Privileges();
