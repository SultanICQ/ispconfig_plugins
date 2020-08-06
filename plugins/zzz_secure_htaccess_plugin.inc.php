<?php
class zzz_secure_htaccess_plugin
{
    var $plugin_name = 'zzz_secure_htaccess_plugin';
    var $class_name = 'zzz_secure_htaccess_plugin';

    var $allowed_ips = [];
    var $production_domains = [];

    //* This function is called during ispconfig installation to determine
    //  if a symlink shall be created for this plugin.
    function onInstall()
    {
        global $conf;

        if ($conf['services']['web'] == true) {
            return true;
        } else {
            return false;
        }
    }

    /*
         This function is called when the plugin is loaded
    */
    function onLoad()
    {
        global $app;

        /*
        Register for the events
        */
        $app->plugins->registerEvent('web_folder_user_insert', $this->plugin_name, 'web_folder_user');
        $app->plugins->registerEvent('web_folder_user_insert', $this->plugin_name, 'add_main_domain_env');
        $app->plugins->registerEvent('web_folder_user_update', $this->plugin_name, 'web_folder_user');
        $app->plugins->registerEvent('web_folder_user_update', $this->plugin_name, 'add_main_domain_env');

        $app->plugins->registerEvent('web_folder_insert', $this->plugin_name, 'web_folder_update');
        $app->plugins->registerEvent('web_folder_insert', $this->plugin_name, 'add_main_domain_env');
        $app->plugins->registerEvent('web_folder_update', $this->plugin_name, 'web_folder_update');
        $app->plugins->registerEvent('web_folder_update', $this->plugin_name, 'add_main_domain_env');

        $app->plugins->registerEvent('web_domain_insert', $this->plugin_name, 'web_domain_update');
        $app->plugins->registerEvent('web_domain_update', $this->plugin_name, 'web_domain_update');

        $this->loadPluginConfig();
    }

    function loadConfigFile($filename, $default = [] ) {
        $file = __DIR__ . DIRECTORY_SEPARATOR . $filename ;
        if ( !file_exists( $file ) ) {
            return $default;
        }
        $config = include($file);
        if ( !is_array($config) ) {
            return $default;
        }
        return $config;
    }

    function loadPluginConfig() {
        $global = $this->loadConfigFile( $this->class_name . '.config.php' );
        $local = $this->loadConfigFile( $this->class_name . '.config.local.php' );

        $config = array_merge( $global, $local );

        if ( !empty($config['allowed_ips']) ) {
            $this->allowed_ips = $config['allowed_ips'];
            if ( !is_array($this->allowed_ips) ) {
                $this->allowed_ips = [];
            }
        }
        if ( !empty($config['production_domains']) ) {
            $this->production_domains = $config['production_domains'];
            if ( !is_array($this->production_domains) ) {
                $this->production_domains = [];
            }
        }
    }

    function buildHtAccess( $path, $website ) {
        global $app;

        $aliases = $this->getAliases($website);
        $ifAliases = [];
        foreach( $aliases as $alias ) {
            $ifAliases[] = preg_quote( $alias );
        }

        $begin_marker = '### ISPConfig folder protection begin ###';
        $end_marker = "### ISPConfig folder protection end ###\n\n";
        $if_begin_marker = sprintf('<If "%%{HTTP_HOST} =~ /^(%s)$/">', implode( '|', $ifAliases) );
        $if_end_marker = '</If>';

        $allowedIPs = [];
        foreach( $this->allowed_ips as $allowed_ip ) {
            $allowedIPs[] = 'require ip ' . $allowed_ip;
        }
        $allowedIPs = implode("\n", $allowedIPs );

        $ht_file =<<<DATA
$begin_marker
AuthType Basic
AuthName "Members Only"
AuthUserFile $path.htpasswd
$if_begin_marker
require valid-user
$allowedIPs
$if_end_marker
$end_marker
DATA;

        if(file_exists($path.'.htaccess')) {
            $old_content = $app->system->file_get_contents($path.'.htaccess');

            if(preg_match('/' . preg_quote($begin_marker, '/') . '(.*?)' . preg_quote($end_marker, '/') . '/s', $old_content, $matches)) {
                $ht_file = str_replace($matches[0], $ht_file, $old_content);
            } else {
                $ht_file .= $old_content;
            }
        }

        $app->system->file_put_contents($path.'.htaccess', $ht_file);
        $app->system->chmod($path.'.htaccess', 0751);
        $app->system->chown($path.'.htaccess', $website['system_user']);
        $app->system->chgrp($path.'.htaccess', $website['system_group']);
        $app->log('Created/modified file '.$path.'.htaccess', LOGLEVEL_DEBUG);

        //* Create empty .htpasswd file, if it does not exist
        if(!is_file($folder_path.'.htpasswd')) {
            $app->system->touch($path.'.htpasswd');
            $app->system->chmod($path.'.htpasswd', 0751);
            $app->system->chown($path.'.htpasswd', $website['system_user']);
            $app->system->chgrp($path.'.htpasswd', $website['system_group']);
            $app->log('Created file '.$path.'.htpasswd', LOGLEVEL_DEBUG);
        }
    }

    function buildHtAccessMainDomain( $path, $website, $server ) {
        global $app;

        $begin_marker = '### MainDomain Environment begin ###';
        $end_marker = "### MainDomain Environment end ###\n\n";

        $domain = $website['domain'];
        $production_domain = $domain;

        if ( !empty($this->production_domains[ $website['domain_id'] ]) ) {
            $production_domain = $this->production_domains[ $website['domain_id'] ];
        }

        $server_ip = preg_quote($server['ip_address']);

        $ht_file = <<<DATA
$begin_marker
<IfModule mod_rewrite.c>
# We set the variable "MAINDOMAIN" with the name of the default QA server.
# If the server's IP is not the QA server's one, the necessary domain is set.
# This block is created when generating the protected directory.
#
# To create redirections for example it would be like this:
# RewriteRule ^folder1/folder2/?$ https://%{ENV:MAIN_DOMAIN}/folder-new/ [R=301,L]
#
# Put the redirections outside and below this marker block.
#
RewriteEngine On
RewriteRule .* - [E=MAIN_DOMAIN:$domain]
RewriteCond %{SERVER_ADDR} !^$server_ip\$
RewriteRule .* - [E=MAIN_DOMAIN:$production_domain]
</IfModule>
$end_marker
DATA;

        if(file_exists($path.'.htaccess')) {
            $old_content = $app->system->file_get_contents($path.'.htaccess');

            if(preg_match('/' . preg_quote($begin_marker, '/') . '(.*?)' . preg_quote($end_marker, '/') . '/s', $old_content, $matches)) {
                $ht_file = str_replace($matches[0], $ht_file, $old_content);
            } else {
                $ht_file .= $old_content;
            }
        }

        $app->system->file_put_contents($path.'.htaccess', $ht_file);
        $app->system->chmod($path.'.htaccess', 0751);
        $app->system->chown($path.'.htaccess', $website['system_user']);
        $app->system->chgrp($path.'.htaccess', $website['system_group']);
        $app->log('Created/modified file '.$path.'.htaccess', LOGLEVEL_DEBUG);
    }

    function getAliases($website ) {
        global $app;

        $website_id = $website['domain_id'];
        if ( $website['type'] == 'alias' ) {
            $website_id = $website['parent_domain_id'];
        }

        $aliases = [];
        $rows = $app->db->queryAllRecords("SELECT domain, subdomain FROM web_domain WHERE domain_id = ? OR parent_domain_id = ?", $website_id, $website_id);
        foreach( $rows as $row ) {
            $aliases[] = $row['domain'];
            if ( $row['subdomain'] == 'www') {
                $aliases[] = 'www.' . $row['domain'];
            }
        }

        return $aliases;
    }

    function buildFolderPath($folder, $website ) {
        global $app;

        $web_folder = 'web';
        if($website['type'] == 'vhostsubdomain' || $website['type'] == 'vhostalias' || $website['type'] == 'alias') {
            $web_folder = $website['web_folder'];
            if ( empty($web_folder) ) {
                $web_folder = 'web';
            }
        }

        //* Get the folder path.

        if(substr($folder['path'], 0, 1) == '/') $folder['path'] = substr($folder['path'], 1);
        if(substr($folder['path'], -1) == '/') $folder['path'] = substr($folder['path'], 0, -1);
        $folder_path = $website['document_root'].'/' . $web_folder . '/'.$folder['path'];
        if(substr($folder_path, -1) != '/') $folder_path .= '/';

        //* Check if the resulting path is inside the docroot
        if(stristr($folder_path, '..') || stristr($folder_path, './') || stristr($folder_path, '\\')) {
            $app->log('Folder path "'.$folder_path.'" contains .. or ./.', LOGLEVEL_DEBUG);
            return false;
        }

        if(substr($folder_path, 0, strlen($website['document_root'])) != $website['document_root']) {
            $app->log('New folder path '.$folder_path.' is outside of docroot.', LOGLEVEL_DEBUG);
            return false;
        }

        //* Create the folder path, if it does not exist
        if(!is_dir($folder_path)) $app->system->mkdirpath($folder_path);

        return $folder_path;
    }

    function web_domain_update($event_name, $data) {
        global $app, $conf;

        $website = $data['new'];

        if(!is_array($website)) {
            $app->log('Not able to retrieve folder or website record.', LOGLEVEL_DEBUG);
            return false;
        }

        $domain_id = $website['domain_id'];
        if ( $website['type'] == 'alias' ) {
            $domain_id = $website['parent_domain_id'];
        }

        $website = $app->db->queryOneRecord("SELECT * FROM web_domain WHERE domain_id = ?", $domain_id);

        $folder = $app->db->queryOneRecord("SELECT * FROM web_folder WHERE parent_domain_id = ? AND path = '/'", $domain_id );
        if ( empty($folder) ) {
            $app->log('Not exists web folder protection active for / path.', LOGLEVEL_DEBUG);
            return false;
        }

        $folder_path = $this->buildFolderPath($folder, $website);
        if ( $folder_path === false ) {
            return false;
        }

        //* Create the .htaccess file
        if($folder['active'] == 'y') {
            $this->buildHtAccess( $folder_path, $website );
        }
    }

    function add_main_domain_env($event_name, $data) {
        global $app, $conf;

        $website = $app->db->queryOneRecord("SELECT * FROM web_domain WHERE domain_id = ?", $data['new']['parent_domain_id']);
        $server = $app->db->queryOneRecord("SELECT * FROM server_ip WHERE server_id = ? and ip_type = ?", $website['server_id'], 'IPv4');

        if(!is_array($website)) {
            $app->log('Not able to retrieve folder or website record.', LOGLEVEL_DEBUG);
            return false;
        }
        if(!is_array($server)) {
            $app->log('Not able to retrieve server record.', LOGLEVEL_DEBUG);
            return false;
        }

        $folder_path = $this->buildFolderPath($data['new'], $website);
        if ( $folder_path === false ) {
            return false;
        }

        //* Create the .htaccess file
        if($data['new']['active'] == 'y') {
            $this->buildHtAccessMainDomain( $folder_path, $website, $server );
        }

    }

    //* Update folder protection, when path has been changed
    function web_folder_update($event_name, $data) {
        global $app, $conf;

        $website = $app->db->queryOneRecord("SELECT * FROM web_domain WHERE domain_id = ?", $data['new']['parent_domain_id']);

        if(!is_array($website)) {
            $app->log('Not able to retrieve folder or website record.', LOGLEVEL_DEBUG);
            return false;
        }

        $folder_path = $this->buildFolderPath($data['new'], $website);
        if ( $folder_path === false ) {
            return false;
        }

        //* Create the .htaccess file
        if($data['new']['active'] == 'y') {
            $this->buildHtAccess( $folder_path, $website );
        }

    }

    //* Create or update the .htaccess folder protection
    function web_folder_user($event_name, $data) {
        global $app, $conf;

        $app->uses('system');

        if($event_name == 'web_folder_user_delete') {
            $folder_id = $data['old']['web_folder_id'];
        } else {
            $folder_id = $data['new']['web_folder_id'];
        }

        $folder = $app->db->queryOneRecord("SELECT * FROM web_folder WHERE web_folder_id = ?", $folder_id);
        $website = $app->db->queryOneRecord("SELECT * FROM web_domain WHERE domain_id = ?", $folder['parent_domain_id']);

        if(!is_array($folder) or !is_array($website)) {
            $app->log('Not able to retrieve folder or website record.', LOGLEVEL_DEBUG);
            return false;
        }

        $folder_path = $this->buildFolderPath( $folder, $website );
        if ( $folder_path === false ) {
            return false;
        }

        if($folder['active'] == 'y') {
            $this->buildHtAccess( $folder_path, $website );
        }

    }
}