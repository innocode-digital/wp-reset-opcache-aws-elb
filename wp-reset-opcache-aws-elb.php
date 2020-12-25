<?php
/**
 * Plugin Name: AWS ELB OPcache reset
 * Description: Resets OPcache on master and all ELB instances.
 * Version: 0.3.1
 * Author: Innocode
 * Author URI: https://innocode.com
 * Requires at least: 4.9.8
 * Tested up to: 5.6
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace AWSELBOPcacheReset;

use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;
use Aws\Ec2\Ec2Client;
use CacheTool\Adapter\FastCGI;
use CacheTool\CacheTool;

define( 'AWS_ELB_OPCACHE_RESET', 'aws_elb_opcache_reset' );

if ( ! defined( 'AWS_ELB_OPCACHE_RESET_PORT' ) ) {
    define( 'AWS_ELB_OPCACHE_RESET_PORT', 8289 );
}

if ( ! defined( 'AWS_ELB_OPCACHE_RESET_FALLBACK' ) ) {
    define( 'AWS_ELB_OPCACHE_RESET_FALLBACK', '127.0.0.1' );
}

/**
 * Checks if necessary constants are set
 *
 * @return bool
 */
function is_enabled() {
    return defined( 'AWS_ELB_OPCACHE_RESET_LOAD_BALANCER' ) &&
        defined( 'AWS_ELB_OPCACHE_RESET_REGION' ) &&
        function_exists( 'opcache_reset' ) &&
        ini_get( 'opcache.enable' );
}

/**
 * Returns ELB
 *
 * @return mixed|null
 */
function get_load_balancers() {
    if ( ! defined( 'AWS_ELB_OPCACHE_RESET_REGION' ) ) {
        trigger_error(
            'Missing required constant AWS_ELB_OPCACHE_RESET_REGION',
            E_USER_WARNING
        );
        return null;
    }

    $elastic_load_balancing = new ElasticLoadBalancingClient( [
        'region'  => AWS_ELB_OPCACHE_RESET_REGION,
        'version' => 'latest',
    ] );

    if ( ! defined( 'AWS_ELB_OPCACHE_RESET_LOAD_BALANCER' ) ) {
        trigger_error(
            'Missing required constant AWS_ELB_OPCACHE_RESET_LOAD_BALANCER',
            E_USER_WARNING
        );
        return null;
    }

    return $elastic_load_balancing->describeLoadBalancers( [
        'LoadBalancerNames' => [ AWS_ELB_OPCACHE_RESET_LOAD_BALANCER ], // Currently support one load balancer
    ] )->get( 'LoadBalancerDescriptions' );
}

/**
 * Returns ELB instances
 *
 * @param array $load_balancer
 *
 * @return \Aws\Result|null
 */
function get_ec2_load_balancer_instances( $load_balancer ) {
    if ( ! defined( 'AWS_ELB_OPCACHE_RESET_REGION' ) ) {
        trigger_error(
            'Missing required constant AWS_ELB_OPCACHE_RESET_REGION',
            E_USER_WARNING
        );
        return null;
    }

    $ec2 = new Ec2Client( [
        'region'  => AWS_ELB_OPCACHE_RESET_REGION,
        'version' => 'latest',
    ] );

    return $ec2->describeInstances( [
        'InstanceIds' => array_column( $load_balancer['Instances'], 'InstanceId' ),
    ] );
}

/**
 * Resets OPcache on instance
 *
 * @param string $host
 *
 * @return bool
 */
function reset_instance( $host ) {
    $adapter = new FastCGI( "$host:" . AWS_ELB_OPCACHE_RESET_PORT );
    $tmp_dir = defined( 'AWS_ELB_OPCACHE_RESET_TMP_DIR' ) ? AWS_ELB_OPCACHE_RESET_TMP_DIR : null;
    $cache = CacheTool::factory( $adapter, $tmp_dir );

    return $cache->opcache_reset();
}

/**
 * Schedules OPcache reset
 *
 * @param string $host
 * @param int    $delay
 */
function schedule( $host, $delay = 0 ) {
    wp_schedule_single_event( time() + $delay, AWS_ELB_OPCACHE_RESET, [
        $host,
    ] );
}

/**
 * Initializes OPcache reset
 */
function reset() {
    opcache_reset();

    if ( AWS_ELB_OPCACHE_RESET_FALLBACK ) {
        schedule( AWS_ELB_OPCACHE_RESET_FALLBACK );
    }

    $load_balancers = get_load_balancers();

    if ( ! empty( $load_balancers ) && is_array( $load_balancers ) ) {
        $x = intval( boolval( AWS_ELB_OPCACHE_RESET_FALLBACK ) );

        foreach ( $load_balancers as $load_balancer ) {
            $instances = get_ec2_load_balancer_instances( $load_balancer );

            if ( ! empty( $instances ) ) {
                foreach ( $instances->get( 'Reservations' ) as $i => $reservation ) {
                    foreach ( $reservation['Instances'] as $j => $instance ) {
                        if ( isset( $instance['State']['Code'] ) && $instance['State']['Code'] == 16 ) {
                            schedule( $instance['PrivateIpAddress'], ( $i + $j + $x ) * MINUTE_IN_SECONDS );
                        }
                    }
                }
            }
        }
    }
}

/**
 * Adds OPcache flush button to WordPress admin panel
 */
function add_flush_button() {
    if ( function_exists( 'mu_add_flush_button' ) ) {
        mu_add_flush_button( __( 'OPcache' ), 'AWSELBOPcacheReset\reset' );
    }

    if ( is_multisite() ) {
        if ( function_exists( 'flush_cache_add_network_button' ) ) {
            flush_cache_add_network_button(
                __( 'OPcache' ),
                'AWSELBOPcacheReset\reset'
            );
        }
    } else {
        if ( function_exists( 'flush_cache_add_button' ) ) {
            flush_cache_add_button(
                __( 'OPcache' ),
                'AWSELBOPcacheReset\reset'
            );
        }
    }
}

/**
 * Loads plugin functionality
 */
function load() {
    add_flush_button();
}

if ( is_enabled() ) {
    if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    add_action( 'plugins_loaded', 'AWSELBOPcacheReset\load' );
    add_action( AWS_ELB_OPCACHE_RESET, 'AWSELBOPcacheReset\reset_instance' );
}
