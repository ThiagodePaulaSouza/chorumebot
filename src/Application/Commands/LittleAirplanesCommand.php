<?php

namespace Chorume\Application\Commands;

use Predis\Client as RedisClient;
use Discord\Discord;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Interaction;
use Discord\Builders\MessageBuilder;
use Chorume\Application\Discord\MessageComposer;
use Chorume\Helpers\RedisHelper;
use Exception;

class LittleAirplanesCommand
{
    private Discord $discord;
    private $config;
    private RedisHelper $redisHelper;
    private MessageComposer $messageComposer;
    private $userRepository;
    private $userCoinHistoryRepository;
    private int $cooldownTimer;
    private int $cooldownLimit;
    private float $extraValueProbability;
    private float $extraValue200Probability;
    private float $minValue;
    private float $maxValue;
    private float $boostedValue;

    public function __construct(
        Discord $discord,
        $config,
        RedisClient $redis,
        $userRepository,
        $userCoinHistoryRepository
    ) {
        $this->discord = $discord;
        $this->config = $config;
        $this->redisHelper = new RedisHelper($redis);
        $this->messageComposer = new MessageComposer($this->discord);
        $this->userRepository = $userRepository;
        $this->userCoinHistoryRepository = $userCoinHistoryRepository;
        $this->cooldownTimer = getenv('LITTLE_AIRPLANES_COOLDOWN_TIMER');
        $this->cooldownLimit = getenv('LITTLE_AIRPLANES_COOLDOWN_LIMIT');
        $this->extraValueProbability = getenv('LITTLE_AIRPLANES_PROBABILITY');
        $this->extraValue200Probability = getenv('LITTLE_AIRPLANES_PROBABILITY_BOOSTED');
        $this->minValue = getenv('LITTLE_AIRPLANES_PROBABILITY_VALUE_MIN');
        $this->maxValue = getenv('LITTLE_AIRPLANES_PROBABILITY_VALUE_MAX');
        $this->boostedValue = getenv('LITTLE_AIRPLANES_PROBABILITY_VALUE_BOOSTED');
    }

    public function fly(Interaction $interaction)
    {
        try {
            if (!find_role_array($this->config['admin_role'], 'name', $interaction->member->roles)) {
                $interaction->respondWithMessage(
                    MessageBuilder::new()->setContent('Você não tem permissão para usar este comando!'),
                    true
                );
                return;
            }

            if (
                !$this->redisHelper->cooldown(
                    'cooldown:littleairplanes:fly:' . $interaction->member->user->id,
                    $this->cooldownTimer,
                    $this->cooldownLimit
                )
            ) {
                $interaction->respondWithMessage(
                    $this->messageComposer->embed(
                        'MAH ÔÊÊ!',
                        sprintf('Aguarde %s minuto para mandar mais aviõeszinhos... ôêê!', $this->cooldownTimer / 60),
                        $this->config['images']['gonna_press']
                    ),
                    true
                );
                return;
            }

            if ($this->userCoinHistoryRepository->reachedMaximumAirplanesToday()) {
                $interaction->respondWithMessage(
                    $this->messageComposer->embed(
                        'MAH ÔÊÊ!',
                        sprintf(
                            'Foram **%s coins** em :airplane_small: aviõeszinhos hoje que não dava pra ver o céu oêê! Agora só amanhã rá rá ê hi hi!',
                            getenv('LITTLE_AIRPLANES_MAXIMUM_AMOUNT_DAY')
                        ),
                        $this->config['images']['see_you_tomorrow'],
                        '#FF0000'
                    )
                );
                return;
            }

            $members = array_keys($this->discord->getChannel($interaction->channel_id)->members->toArray());

            if (empty($members)) {
                $interaction->respondWithMessage($this->messageComposer->embed(
                    'MAH ÔÊÊ!',
                    'Ma, ma, ma, mas tem ninguém nessa sala, não tem como eu jogar meus :airplane_small:aviõeszinhos... ôêê!'
                ));
            }

            $interaction->acknowledgeWithResponse()->then(function () use ($interaction, $members) {
                $interaction->updateOriginalResponse($this->messageComposer->embed(
                    'MAH ÔÔÊ!',
                    'Olha só quero ver, quero ver quem vai pegar os aviõeszinhos... ôêê!',
                    $this->config['images']['airplanes']
                ));

                $loop = $this->discord->getLoop();
                $loop->addTimer(5, function () use ($members, $interaction) {
                    $airplanes = [];

                    foreach ($members as $member) {
                        if (mt_rand(0, 99) < $this->extraValueProbability * 100) {
                            if (!$this->userRepository->userExistByDiscordId($member)) continue;

                            $extraValue = mt_rand(0, 99) < $this->extraValue200Probability * 100
                                ? $this->boostedValue
                                : mt_rand($this->minValue, $this->maxValue);

                            $airplanes[] = [
                                'discord_user_id' => $member,
                                'amount' => $extraValue
                            ];

                            $user = $this->userRepository->getByDiscordId($member);
                            $this->userCoinHistoryRepository->create($user[0]['id'], $extraValue, 'Airplane');
                        }
                    }

                    if (empty($airplanes)) {
                        $interaction->updateOriginalResponse($this->messageComposer->embed(
                            'MAH ÔÔÊ!',
                            'Acho que o Roque esqueceu de fazer meus :airplane_small:aviõeszinhos... ôêê!',
                            $this->config['images']['silvio_thats_ok'],
                        ));
                        return;
                    }

                    /**
                     * @var Embed $embed
                     */
                    $embed = $this->discord->factory(Embed::class);
                    $embed
                        ->setTitle('MAH ÔÔÊ!')
                        ->setColor('#8FCE00')
                        ->setDescription('Os :airplane_small:aviõeszinhos voaram pelo auditório e caíram em cima de:')
                        ->setImage($this->config['images']['silvio_cheers']);

                    $airports = '';
                    $amount = '';

                    foreach ($airplanes as $airplane) {
                        $airports .= sprintf("<@%s> \n", $airplane['discord_user_id']);
                        $amount .= sprintf(
                            "%s %s \n",
                            $airplane['amount'] < $this->boostedValue
                                ? ':airplane_small:'
                                : ':airplane:',
                            $airplane['amount']
                        );
                    }

                    $embed
                        ->addField(['name' => 'Nome', 'value' => $airports, 'inline' => 'true'])
                        ->addField(['name' => 'Valor (C$)', 'value' => $amount, 'inline' => 'true']);

                    $interaction->updateOriginalResponse(MessageBuilder::new()->addEmbed($embed));
                });

                return;
            });
        } catch (Exception $e) {
            $this->discord->getLogger()->error($e->getMessage());
        }
    }
}
