<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts\Records;

/**
 * A persisted subscription. Its plan is read through getAttribute('plan').
 */
interface SubscriptionRecord extends Record {}
