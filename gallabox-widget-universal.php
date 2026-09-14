<?php
/**
 * ====================================================================
 * Gallabox WhatsApp Widget Universal Module
 * Renders the Gallabox WhatsApp floating chat widget across subdomains
 * with dynamic university messageText and central admin configuration.
 * ====================================================================
 */

if (!function_exists('sode_gallabox_widget_render')) {
    function sode_gallabox_widget_render($params = [])
    {
        $uni_slug = $params['uni'] ?? ($params['university'] ?? '');
        if (empty($uni_slug) && function_exists('sode_client_detect_uni')) {
            $uni_slug = sode_client_detect_uni();
        }

        // 1. Fetch Gallabox settings from DB (if local DB connection exists)
        $db = null;
        if (function_exists('get_db_connection')) {
            try {
                $db = get_db_connection();
            } catch (Exception $e) {
                $db = null;
            }
        } elseif (file_exists(__DIR__ . '/admin/config/config.php')) {
            try {
                require_once __DIR__ . '/admin/config/config.php';
                if (function_exists('get_db_connection')) {
                    $db = get_db_connection();
                }
            } catch (Exception $e) {
                $db = null;
            }
        }

        $settings = [];
        $uni_data = null;

        if ($db) {
            try {
                $stmt = $db->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_group = 'gallabox'");
                $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                // Fetch university specific data
                if ($uni_slug) {
                    $u_stmt = $db->prepare("SELECT id, full_name, short_name, mode, gallabox_message_text FROM universities WHERE (slug = ? OR LOWER(short_name) = ? OR LOWER(full_name) = ?) AND is_active = 1 LIMIT 1");
                    $u_stmt->execute([$uni_slug, strtolower($uni_slug), strtolower($uni_slug)]);
                    $uni_data = $u_stmt->fetch();
                }
            } catch (Exception $e) {
                // Fallback to defaults
            }
        }

        // Master Enable/Disable check
        $is_enabled = !isset($settings['gallabox_widget_enabled']) || $settings['gallabox_widget_enabled'] !== '0';
        if (!$is_enabled) {
            return '';
        }

        // Check if raw custom script override is set
        if (!empty($settings['gallabox_custom_script'])) {
            return $settings['gallabox_custom_script'];
        }

        // Resolve parameters with defaults
        $wa_id = !empty($settings['gallabox_wa_id']) ? $settings['gallabox_wa_id'] : '+917065777755';
        $site_name = !empty($settings['gallabox_site_name']) ? $settings['gallabox_site_name'] : 'SODE ™';
        $site_tag = !empty($settings['gallabox_site_tag']) ? $settings['gallabox_site_tag'] : 'School of Online & Distance Education ';
        $site_logo = !empty($settings['gallabox_site_logo']) ? $settings['gallabox_site_logo'] : 'https://distanceeducationschool.com/wp-content/uploads/2025/01/sode-white-favicon.png';
        $position = (!empty($settings['gallabox_widget_position']) && in_array($settings['gallabox_widget_position'], ['LEFT', 'RIGHT'])) ? $settings['gallabox_widget_position'] : 'RIGHT';
        $trigger_msg = !empty($settings['gallabox_trigger_message']) ? $settings['gallabox_trigger_message'] : 'Get Help';
        $welcome_msg = !empty($settings['gallabox_welcome_message']) ? $settings['gallabox_welcome_message'] : "Welcome to SODE™ (School of Online & Distance Education) India's Top Reliable Portal for Free Counseling & Suggestion of UGC-DEB Approved Universities and Courses.";
        $brand_color = !empty($settings['gallabox_brand_color']) ? $settings['gallabox_brand_color'] : '#25D366';
        $attribution = !empty($settings['gallabox_brand_attribution']) ? $settings['gallabox_brand_attribution'] : 'I Love Gallabox';
        $cdn_url = !empty($settings['gallabox_script_url']) ? rtrim($settings['gallabox_script_url'], '/') : 'https://waw.gallabox.com';

        // Reply options
        $replies_str = !empty($settings['gallabox_reply_options']) ? $settings['gallabox_reply_options'] : 'Yes';
        $replies_arr = array_filter(array_map('trim', explode(',', $replies_str)));
        if (empty($replies_arr)) {
            $replies_arr = ['Yes'];
        }
        $replies_json = json_encode(array_values($replies_arr));

        // Resolve messageText
        $full_name = $uni_data['full_name'] ?? 'Dayananda Sagar University';
        $short_name = $uni_data['short_name'] ?? 'DSU';
        $mode = $uni_data['mode'] ?? 'Online';

        $raw_msg = !empty($uni_data['gallabox_message_text']) ? $uni_data['gallabox_message_text'] : (!empty($settings['gallabox_default_message_text']) ? $settings['gallabox_default_message_text'] : 'Start Your {UNIVERSITY_NAME} {MODE} Counseling with an Expert Now');

        $resolved_msg = str_replace(
            ['{UNIVERSITY_NAME}', '{UNI}', '{MODE}'],
            [$full_name, $short_name, $mode],
            $raw_msg
        );

        // Escape for JS string
        $js_wa_id = addslashes($wa_id);
        $js_site_name = addslashes($site_name);
        $js_site_tag = addslashes($site_tag);
        $js_site_logo = addslashes($site_logo);
        $js_position = addslashes($position);
        $js_trigger_msg = addslashes($trigger_msg);
        $js_welcome_msg = addslashes($welcome_msg);
        $js_brand_color = addslashes($brand_color);
        $js_message_text = addslashes($resolved_msg);
        $js_attribution = addslashes($attribution);
        $js_cdn_url = addslashes($cdn_url);

        ob_start();
        ?>
<!-- Gallabox WhatsApp -->
<script>
(function (w, d, s, u) {
w.gbwawc = {
url: u,
options: {
        waId: "<?php echo $js_wa_id; ?>",
        siteName: "<?php echo $js_site_name; ?>",
        siteTag: "<?php echo $js_site_tag; ?>",
        siteLogo: "<?php echo $js_site_logo; ?>",
        widgetPosition: "<?php echo $js_position; ?>",
        triggerMessage: "<?php echo $js_trigger_msg; ?>",
        welcomeMessage: "<?php echo $js_welcome_msg; ?>",
        brandColor: "<?php echo $js_brand_color; ?>",
        messageText: "<?php echo $js_message_text; ?>" + getParameterByName('messageText'),
        replyOptions: <?php echo $replies_json; ?>,
        brandAttribution: "<?php echo $js_attribution; ?>"
    },
};

function getParameterByName(name) {
  name = name.replace(/[\[]/, "\\[").replace(/[\]]/, "\\]");
  var regex = new RegExp("[\\?&]" + name + "=([^&#]*)"),
      results = regex.exec(location.search);
  return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
}

var h = d.getElementsByTagName(s)[0],
j = d.createElement(s);
j.async = true;
j.src = u + "/whatsapp-widget.min.js?_=" + Math.random();
h.parentNode.insertBefore(j, h);
})(window, document, "script", "<?php echo $js_cdn_url; ?>");
</script>
        <?php
        return ob_get_clean();
    }
}
