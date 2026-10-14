<?php

declare(strict_types=1);

namespace Bayurifkialghifari\Payments;

use Bayurifkialghifari\Payments\Contracts\PaymentGateway;
use Bayurifkialghifari\Payments\Gateways\DokuGateway;
use Bayurifkialghifari\Payments\Gateways\IPaymuGateway;
use Bayurifkialghifari\Payments\Gateways\MidtransGateway;
use Bayurifkialghifari\Payments\Gateways\PaywuzGateway;
use Bayurifkialghifari\Payments\Gateways\XenditGateway;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payments.php', 'payments');
        $this->app->singleton(PaymentsManager::class);
        $this->app->alias(PaymentsManager::class, PaymentGateway::class);
        foreach ([
            MidtransGateway::class => 'midtrans',
            XenditGateway::class => 'xendit',
            DokuGateway::class => 'doku',
            PaywuzGateway::class => 'paywuz',
            IPaymuGateway::class => 'ipaymu',
        ] as $class => $method) {
            $this->app->bind($class, fn (Application $app) => $app->make(PaymentsManager::class)->{$method}());
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/payments.php' => config_path('payments.php')], 'payments-config');
    }
}
