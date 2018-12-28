<?php
/**
 * Plugin Name: AWS ELB OPCache reset
 * Description: Resets OPCache on master and all ELB instances.
 * Version: 0.1.0
 * Author: Innocode
 * Author URI: https://innocode.com
 * Requires at least: 4.9.8
 * Tested up to: 4.9.8
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace AWSELBOPCacheReset;

use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;
use Aws\Ec2\Ec2Client;
use CacheTool\Adapter\FastCGI;
use CacheTool\CacheTool;

define( 'AWS_ELB_OPCACHE_RESET_VERSION', '0.1.0' );
define( 'AWS_ELB_OPCACHE_RESET', 'aws_elb_opcache_reset' );

if ( !defined( 'AWS_ELB_OPCACHE_RESET_PORT' ) ) {
    define( 'AWS_ELB_OPCACHE_RESET_PORT', 8289 );
}

if ( !defined( 'AWS_ELB_OPCACHE_RESET_FALLBACK' ) ) {
    define( 'AWS_ELB_OPCACHE_RESET_FALLBACK', '127.0.0.1' );
}

/**
 * Checks if necessary constants are set
 *
 * @return bool
 */
function is_enabled() {
    return defined( 'AWS_ELB_OPCACHE_RESET_LOAD_BALANCER' )
        && defined( 'AWS_ELB_OPCACHE_RESET_REGION' )
        && function_exists( 'opcache_reset' )
        && ini_get( 'opcache.enable' );
}

/**
 * Returns ELB
 *
 * @return mixed|null
 */
function get_load_balancers() {
    $elastic_load_balancing = new ElasticLoadBalancingClient( [
        'region'  => AWS_ELB_OPCACHE_RESET_REGION,
        'version' => 'latest',
    ] );

    return $elastic_load_balancing->describeLoadBalancers( [
        'LoadBalancerNames' => [ AWS_ELB_OPCACHE_RESET_LOAD_BALANCER ], // Currently support one load balancer
    ] )->get( 'LoadBalancerDescriptions' );
}

/**
 * Returns ELB instances
 *
 * @param array $load_balancer
 *
 * @return \Aws\Result
 */
function get_ec2_load_balancer_instances( $load_balancer ) {
    $ec2 = new Ec2Client( [
        'region'  => AWS_ELB_OPCACHE_RESET_REGION,
        'version' => 'latest',
    ] );

    return $ec2->describeInstances( [
        'InstanceIds' => array_column( $load_balancer['Instances'], 'InstanceId' ),
    ] );
}

/**
 * Resets OPCache on instance
 *
 * @param string $host
 *
 * @return bool
 */
function reset_instance( $host ) {
    $chroot = defined( 'AWS_ELB_OPCACHE_RESET_TMP_DIR' ) ? AWS_ELB_OPCACHE_RESET_TMP_DIR : null;
    $adapter = new FastCGI( "$host:" . AWS_ELB_OPCACHE_RESET_PORT, $chroot );
    $cache = CacheTool::factory( $adapter );

    return $cache->opcache_reset();
}

/**
 * Schedules OPCache reset
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
 * Initializes OPCache reset
 */
function reset() {
    opcache_reset();

    if ( AWS_ELB_OPCACHE_RESET_FALLBACK ) {
        schedule( AWS_ELB_OPCACHE_RESET_FALLBACK );
    }

    $load_balancers = get_load_balancers();

    if ( !empty( $load_balancers ) && is_array( $load_balancers ) ) {
        $x = intval( boolval( AWS_ELB_OPCACHE_RESET_FALLBACK ) );

        foreach ( $load_balancers as $load_balancer ) {
            $instances = get_ec2_load_balancer_instances( $load_balancer );

            foreach ( $instances->get( 'Reservations' ) as $i => $reservation ) {
                foreach ( $reservation['Instances'] as $j => $instance ) {
                    schedule( $instance['PrivateIpAddress'], ( $i + $j + $x ) * MINUTE_IN_SECONDS );
                }
            }
        }
    }
}

/**
 * Adds OPCache flush button to WordPress admin panel
 */
function add_flush_button() {
    if ( function_exists( 'mu_add_flush_button' ) && current_user_can( 'manage_options' ) ) {
        mu_add_flush_button( __( 'OPCache' ), 'AWSELBOPCacheReset\reset' );
    }
}

/**
 * Loads plugin functionality
 */
function load() {
    add_flush_button();
}

if ( is_enabled() ) {
    require_once __DIR__ . '/vendor/autoload.php';

    add_action( 'plugins_loaded', 'AWSELBOPCacheReset\load' );
    add_action( AWS_ELB_OPCACHE_RESET, 'AWSELBOPCacheReset\reset_instance' );
}