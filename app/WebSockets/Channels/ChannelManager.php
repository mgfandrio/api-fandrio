<?php 

namespace App\WebSockets\Channels;

use Reverb\WebSockets\Channels\ChannelManager as BaseChannelManager;

class ChannelManager extends BaseChannelManager 
{
    protected function getChannelClass(string $channelName): string
    {
        if (str_starts_with($channelName, 'sieges_update_')) {
            return SiegesChannel::class;
        }

        return parent::getChannelClassName($channelName);
    }
}