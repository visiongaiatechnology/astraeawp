<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea;

/**
 * Single source of truth for the AstraeaOS WP distribution version.
 *
 * VERSION  : AstraeaOS WP distribution release (independent of upstream).
 * WP_BASE  : Upstream WordPress core release this distribution is ported onto.
 */
final class Version
{
    public const VERSION = '0.8.0';

    public const WP_BASE = '7.1.5';
}
