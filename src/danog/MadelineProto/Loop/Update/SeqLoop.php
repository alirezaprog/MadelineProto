<?php
/**
 * Update feeder loop.
 *
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2018 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 *
 * @link      https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Loop\Update;

use Amp\Success;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Loop\Impl\ResumableSignalLoop;

/**
 * update feed loop.
 *
 * @author Daniil Gentili <daniil@daniil.it>
 */
class SeqLoop extends ResumableSignalLoop
{
    use \danog\MadelineProto\Tools;
    private $incomingUpdates = [];
    private $channelId;
    private $feeder;

    public function __construct($API)
    {
        $this->API = $API;
    }
    public function loop()
    {
        $API = $this->API;
        $feeder = $this->feeder = $API->feeders[false];

        if (!$this->API->settings['updates']['handle_updates']) {
            yield new Success(0);

            return false;
        }

        $this->startedLoop();
        $API->logger->logger("Entered update seq loop", Logger::ULTRA_VERBOSE);
        while (!$this->API->settings['updates']['handle_updates'] || !$this->has_all_auth()) {
            if (yield $this->waitSignal($this->pause())) {
                $API->logger->logger("Exiting update seq loop");
                $this->exitedLoop();

                return;
            }
        }
        $this->state = yield $API->load_update_state_async();

        while (true) {
            while (!$this->API->settings['updates']['handle_updates'] || !$this->has_all_auth()) {
                if (yield $this->waitSignal($this->pause())) {
                    $API->logger->logger("Exiting update seq loop");
                    $this->exitedLoop();

                    return;
                }
            }
            if (yield $this->waitSignal($this->pause())) {
                $API->logger->logger("Exiting update seq loop");
                $this->exitedLoop();

                return;
            }
            if (!$this->settings['updates']['handle_updates']) {
                $API->logger->logger("Exiting update seq loop");
                $this->exitedLoop();
                return;
            }
            while ($this->incomingUpdates) {
                $updates = $this->incomingUpdates;
                $this->incomingUpdates = null;
                yield $this->parse($updates);
                $updates = null;
            }
            $feeder->resumeDefer();
        }
    }
    public function parse($updates)
    {
        reset($updates);
        while ($updates) {
            $options = [];
            $key = key($updates);
            $update = $updates[$key];
            unset($updates[$key]);
            $options = $update['options'];
            $updates = $update['updates'];
            unset($update);

            $seq_start = $options['seq_start'];
            $seq_end = $options['seq_end'];
            $result = $this->state->checkSeq($seq_start);
            if ($result > 0) {
                $this->logger->logger('Seq hole of $result. seq_start: '.$seq_start.' != cur seq: '.$this->state->seq().' + 1', \danog\MadelineProto\Logger::ERROR);
                yield $this->updaters[false]->resume();

                continue;
            }
            if ($result < 0) {

            }
            if ($this->state->seq() !== $seq) {
                $this->state->seq($seq);
                if (isset($options['date'])) {
                    $this->state->date($options['date']);
                }
            }

            $this->save($updates);
        }
    }
    public function feed($updates)
    {
        $this->incomingUpdates[] = $updates;
    }
    public function save($updates)
    {
        $this->feeder->feed($updates);
    }
    public function has_all_auth()
    {
        if ($this->API->isInitingAuthorization()) {
            return false;
        }

        foreach ($this->API->datacenter->sockets as $dc) {
            if (!$dc->authorized || $dc->temp_auth_key === null) {
                return false;
            }
        }

        return true;
    }
}
