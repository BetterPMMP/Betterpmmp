<?php
declare(strict_types=1);
namespace BetterPlots\Domain;

final class Access{

    public static function role(array $plot, string $uuid): string{
        if(in_array($uuid, $plot['owners'], true)){ return 'owner'; }
        if(in_array($uuid, $plot['denied'], true)){ return 'denied'; }
        foreach(['trusted'=>'trusted','members'=>'member'] as $list=>$role){
            if(in_array($uuid, $plot[$list], true) || in_array('*', $plot[$list], true)){ return $role; }
        }
        return in_array('*', $plot['denied'], true) ? 'denied' : 'guest';
    }

    public static function build(array $plot, string $uuid, array $online, bool $bypass = false): bool{
        if($bypass){ return true; }
        if(($plot['flags']['done'] ?? false) !== false){ return false; }
        $role = self::role($plot, $uuid);
        return $role === 'owner' || $role === 'trusted' || ($role === 'member' && array_intersect($plot['owners'], $online) !== []);
    }

    public static function enter(array $plot, string $uuid, bool $bypass = false): bool{
        if($bypass){ return true; }
        $role = self::role($plot, $uuid);
        return $role !== 'denied' && (($plot['flags']['untrusted-visit'] ?? true) || in_array($role, ['owner','trusted'], true));
    }
}
