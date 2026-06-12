<?php
declare(strict_types=1);

namespace Loro;

final class Version
{
    public static function equals(VersionVector|Frontiers $left, VersionVector|Frontiers $right): bool
    {
        if ($left instanceof VersionVector && $right instanceof VersionVector) {
            return $left->eq($right);
        }

        if ($left instanceof Frontiers && $right instanceof Frontiers) {
            return $left->eq($right);
        }

        return false;
    }

    public static function versionVectorEquals(VersionVector $left, VersionVector $right): bool
    {
        return $left->eq($right);
    }

    public static function frontiersEquals(Frontiers $left, Frontiers $right): bool
    {
        return $left->eq($right);
    }
}
