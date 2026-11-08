<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/workflows-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/workflows-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/workflows-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/workflows-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/workflows-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/workflows-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=workflows-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Workflows for Laravel

Versioned workflows stored as JSON graphs and run step by step on your queues: triggers, conditions,
approvals, durable waits, business-time delays, escalations, retries, dry runs and templates. Your
users edit a workflow in a builder; the engine runs each published version exactly as it was
published.

## Installation

Requires PHP 8.4, Laravel 13, and a MySQL or PostgreSQL database.

```bash
composer require roundly-consulting/workflows-for-laravel
php artisan workflows:install
php artisan migrate
```

Delays, timeouts, retries, escalations and schedules wake on the scheduler, so add one line:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('workflows:wake')->everyMinute()->withoutOverlapping(10)->onOneServer();
```

## Usage

```php
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Graph\Build\{Party, Trigger, When};

// Once, in a service provider: what a purchase request looks like to the engine.
Workflows::subjects()->register(
    Workflows::subject('purchase_request', PurchaseRequest::class)
        ->label('Purchase request')
        ->ownedBy('team')
        ->field('amount', 'decimal', 'Amount')
        ->field('requester', 'party', 'Requester')
        ->field('approver', 'party', 'Approver'),
);

// The workflow itself is data: a JSON graph your users edit in a builder — or written in PHP.
$definition = Workflows::definitions()->create(key: 'purchase-approval', name: 'Purchase approval',
    subjectType: 'purchase_request', owner: $team);

Workflows::definition('purchase-approval', $team)->edit(Workflows::graph()   // or ->edit(json_decode($json, true))
    ->trigger('start', Trigger::manual())
    ->condition('check', When::field('subject.amount')->gt('500'))
    ->approval('approve', approvers: [Party::field('subject.approver')])
    ->end('done')
    ->connect('start', 'check')
    ->connect('check', 'approve', on: 'true')
    ->connect('check', 'done', on: 'false')
    ->connect('approve', 'done', on: ['approved', 'rejected', 'expired']));
Workflows::definition('purchase-approval', $team)->publish(by: $admin);

// Start it (this graph's trigger is `manual`) — with an `event` trigger the app event starts it instead.
$result = Workflows::start('purchase-approval', subject: $request, owner: $team)
    ->by($request->requester)
    ->idempotencyKey("submit:{$request->id}")
    ->dispatch();

$result->instance->status;   // InstanceStatus::Waiting — at the approval step
```

Approval, notify, transition, opening-hours and form steps light up when the matching Roundly
package is installed; everything else works on its own.

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/workflows-for-laravel](https://roundly-consulting.com/open-source/docs/workflows-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=workflows-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=workflows-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=workflows-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
