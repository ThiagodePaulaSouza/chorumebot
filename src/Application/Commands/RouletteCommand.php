<?php

namespace Chorume\Application\Commands;

use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use Chorume\Repository\Roulette;
use Chorume\Repository\RouletteBet;
use Discord\Parts\Embed\Embed;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\ActionRow;
use Chorume\Repository\User;
use Predis\Client as RedisClient;
use function PHPSTORM_META\map;
use function RingCentral\Psr7\copy_to_string;

class Player
{
    public $user;
    public int $bet;
    public $choice;
    public $userName;

    public function __construct($user, $bet, $choice, $userName)
    {
        $this->user = $user;
        $this->bet = $bet;
        $this->choice = $choice;
        $this->userName = $userName;
    }
}

class GameData
{
    public int $AmountRed = 0;
    public int $AmountGreen = 0;
    public int $AmountBlack = 0;
    public int $AmountTotal = 0;
    public array $jogadores;
    public int $rouletteId = 0;

    public function __construct($rouletteId)
    {
        $this->AmountRed = 0;
        $this->AmountGreen = 0;
        $this->AmountBlack = 0;
        $this->AmountTotal = 0;
        $this->jogadores = [];
        $this->rouletteId = $rouletteId;
    }
}

class RouletteCommand
{
    public $discord;
    public $config;
    public Roulette $rouletteRepository;
    public RouletteBet $rouletteBetRepository;
    public User $userRepository;
    private RedisClient $redis;

    public function __construct(
        Discord $discord,
        $config,
        Roulette $rouletteRepository,
        RouletteBet $rouletteBetRepository,
        User $userRepository,
        RedisClient $redis,
    ) {
        $this->discord = $discord;
        $this->config = $config;
        $this->rouletteRepository = $rouletteRepository;
        $this->rouletteBetRepository = $rouletteBetRepository;
        $this->userRepository = $userRepository;
        $this->redis = $redis;
    }

    public function create(Interaction $interaction)
    {
        if (!find_role_array($this->config['admin_role'], 'name', $interaction->member->roles)) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Você não tem permissão para usar este comando!'), true);
            return;
        }

        $eventName = $interaction->data->options['criar']->options['nome']->value;
        $value = $interaction->data->options['criar']->options['valor']->value;


        if ($this->rouletteRepository->createEvent(strtoupper($eventName), $value)) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent("Roleta criado com sucesso! Valor por aposta: **C\${$value}**"), true);
        }
    }

    public function list(Interaction $interaction)
    {
        $interaction->acknowledgeWithResponse()->then(function () use ($interaction) {
            $roulettesOpen = $this->rouletteRepository->listEventsOpen();
            $roulettesClosed = $this->rouletteRepository->listEventsClosed();

            if (!is_array($roulettesOpen)) {
                $roulettesOpen = [];
            }

            if (!is_array($roulettesClosed)) {
                $roulettesClosed = [];
            }

            $roulettes = [...$roulettesOpen, ...$roulettesClosed];

            $ephemeralMsg = true;

            if (find_role_array($this->config['admin_role'], 'name', $interaction->member->roles)) {
                $ephemeralMsg = false;
            }

            $roulettesDescription = "\n";

            if (empty($roulettes)) {
                $interaction->updateOriginalResponse(
                    MessageBuilder::new()->setContent("Não há Roletas abertas!")
                );
                return;
            }

            foreach ($roulettes as $event) {
                $roulettesDescription .= sprintf(
                    "**[%s] %s (Bet: C$ %s)**\n**Status: %s**\n \n \n",
                    $event['roulette_id'],
                    strtoupper($event['description']),
                    strtoupper($event['amount']),
                    $this->rouletteRepository::LABEL_LONG[(int) $event['status']]
                );
            }

            /**
             * @var Embed $embed
             */
            $embed = $this->discord->factory(Embed::class);
            $embed
                ->setTitle("ROLETAS")
                ->setColor('#F5D920')
                ->setDescription($roulettesDescription)
                ->setImage($this->config['images']['roulette']['list']);
            $interaction->updateOriginalResponse(MessageBuilder::new()->addEmbed($embed), $ephemeralMsg);
        });
    }

    public function close(Interaction $interaction)
    {
        $interaction->acknowledgeWithResponse(true)->then(function() use ($interaction) {
            if (!find_role_array($this->config['admin_role'], 'name', $interaction->member->roles)) {
                $interaction->updateOriginalResponse(
                    MessageBuilder::new()->setContent('Você não tem permissão para usar este comando!')
                );
                return;
            }

            $rouletteId = $interaction->data->options['fechar']->options['id']->value;
            $event = $this->rouletteRepository->getRouletteById($rouletteId);

            if ($event[0]['status'] !== $this->rouletteRepository::OPEN) {
                $interaction->updateOriginalResponse(MessageBuilder::new()->setContent(
                    sprintf("Roleta **#%s** precisa estar aberta para ser fechada!", $rouletteId)
                ));
                return;
            }

            if (empty($event)) {
                $interaction->updateOriginalResponse(
                    MessageBuilder::new()->setContent(
                        sprintf('Roleta **#%s** não existe!', $rouletteId)
                    )
                );
                return;
            }

            if (!$this->rouletteRepository->closeEvent($rouletteId)) {
                $interaction->updateOriginalResponse(
                    MessageBuilder::new()->setContent(
                        sprintf('Ocorreu um erro ao finalizar Roleta **#%s**', $rouletteId)
                    )
                );
                return;
            }

            $interaction->updateOriginalResponse(
                MessageBuilder::new()->setContent(
                    sprintf('Roleta **#%s** fechada! Esse evento não recebe mais apostas!', $rouletteId)
                )
            );

            return;
        });
    }

    public function finish(Interaction $interaction)
    {
        $interaction->acknowledgeWithResponse(false)->then(function () use ($interaction) {
            $eventId = $interaction->data->options['girar']->options['id']->value;
            $event = $this->rouletteRepository->getRouletteById($eventId);
            $status = (int) $event[0]['status'];
            $winnerNumber = rand(0, 14);
            $winnerResult = null;
            $choice = null;

            if (!find_role_array($this->config['admin_role'], 'name', $interaction->member->roles)) {
                $interaction->updateOriginalResponse(
                    MessageBuilder::new()->setContent('Você não tem permissão para usar este comando!'),
                    true
                );
                return;
            }

            if (empty($event)) {
                $interaction->updateOriginalResponse(
                    MessageBuilder::new()->setContent('Roleta não existe!'),
                    true
                );
                return;
            }

            if ($status !== $this->rouletteRepository::CLOSED) {
                $message = sprintf('Roleta **#%s** precisa estar fechada para ser Girada!', $eventId);

                if ($status === $this->rouletteRepository::PAID) {
                    $message = sprintf('Roleta **#%s** já foi finalizada!', $eventId);
                }

                $interaction->updateOriginalResponse(
                    MessageBuilder::new()->setContent($message),
                    true
                );
                return;
            }

            if ($winnerNumber == 0) {
                $winnerResult = Roulette::GREEN;
                $choice = "🟩 G[$winnerNumber]";
            } elseif ($winnerNumber % 2 == 0) {
                $winnerResult = Roulette::BLACK;
                $choice = "⬛ BL[$winnerNumber]";
            } else {
                $winnerResult = Roulette::RED;
                $choice = "🟥 R[$winnerNumber]";
            }

            $bets = $this->rouletteRepository->payoutRoulette($eventId, $winnerResult);

            $eventsDescription = sprintf(
                "**Evento:** %s \n **Vencedor**: %s \n \n \n",
                $event[0]['description'],
                "{$choice}",
            );

            $winnersImage = $this->config['images']['winners'][array_rand($this->config['images']['winners'])];

            /**
             * @var Embed $embed
             */
            $embed = $this->discord->factory(Embed::class);
            $embed
                ->setTitle(sprintf("ROLETA ENCERRADA 💰\n[%s] %s", $eventId, $event[0]['description']))
                ->setColor('#F5D920')
                ->setDescription($eventsDescription)
                ->setImage($winnersImage);

            $earningsByUser = [];

            foreach ($bets as $bet) {
                if ($bet['choice_key'] == $winnerResult) {
                    if (!isset($earningsByUser[$bet['discord_user_id']])) {
                        $earningsByUser[$bet['discord_user_id']] = 0;
                    }
                    $earningsByUser[$bet['discord_user_id']] += intval($bet['earnings']);
                }
            }

            $awarded = '';
            $amount = '';

            foreach ($earningsByUser as $userId => $earnings) {
                $awarded .= sprintf("<@%s>\n", $userId);
                $amount .= sprintf("🪙 %s\n", $earnings);
            }

            $embed
                ->addField(['name' => 'Premiação', 'value' => $awarded, 'inline' => 'true'])
                ->addField(['name' => 'Valor (C$)', 'value' => $amount, 'inline' => 'true']);

            $descriptions = $this->config['images']['roulette']['numbers'];
            $imageRouletteSpin = $this->config['images']['roulette']['spin'];

            if (count($bets) === 0) {
                $embednovo = new Embed($this->discord);
                $embednovo
                    ->setTitle(sprintf('ROLETA #%s ENCERRADA', $eventId))
                    ->setColor('#F5D920')
                    ->setDescription("**Resultado**: Não houveram vencedores.");

                $embed = $embednovo;
            }

            $embedLoop = new Embed($this->discord);
            $embedLoop->setImage($imageRouletteSpin);
            $embedLoop->setTitle(sprintf('ROLETA #%s ENCERRADA', $eventId));
            $embedLoop->setDescription("**Sorteando um número!**");

            $builderLoop = new MessageBuilder();
            $builderLoop->addEmbed($embedLoop);
            $interaction->updateOriginalResponse($builderLoop, false);

            $loop = $this->discord->getLoop();
            $loop->addTimer(8, function () use ($embed, $interaction, $descriptions, $winnerNumber) {
                $embed->setImage($descriptions[$winnerNumber]);
                $builder = new MessageBuilder();
                $builder->addEmbed($embed);
                $interaction->updateOriginalResponse($builder, false);
            });
        });
    }

    public function expose(Interaction $interaction)
    {
        $rouletteId = $interaction->data->options['apostar']->options['id']->value;
        $builder = MessageBuilder::new();
        $action = ActionRow::new();
        $roulette = $this->rouletteRepository->getRouletteById($rouletteId);
        $amountBet = (int) $roulette[0]['amount'];
        $status = (int) $roulette[0]['status'];
        $embed = new Embed($this->discord);

        if (empty($roulette)) {
            $interaction->respondWithMessage(
                MessageBuilder::new()->setContent('Roleta não existe!'),
                true
            );
            return;
        }

        if ($status !== $this->rouletteRepository::OPEN) {
            $interaction->respondWithMessage(
                MessageBuilder::new()->setContent('Roleta precisa estar aberta para apostar!'),
                true
            );
            return;
        }

        $gameData = $this->redis->get($rouletteId);
        $gameData = unserialize($gameData ?? '');

        if (!$gameData) {
            $gameData = new GameData($rouletteId);
            $serializado = serialize($gameData);
            $this->redis->set("roulette:{$rouletteId}", $serializado);
        }

        $buttonRed = Button::new(Button::STYLE_DANGER)->setLabel("R +{$amountBet}")->setListener(function (Interaction $interactionUser) use ($interaction, $rouletteId, $roulette, $amountBet, &$gameData) {
            $fromDiscordId = $interactionUser->member->user->id;
            $userDiscord = $interactionUser->member->user;

            $this->apostarRoleta(
                $fromDiscordId,
                Roulette::RED,
                $rouletteId,
                $interaction,
                $roulette,
                $amountBet,
                $gameData,
                $userDiscord,
                $interactionUser
            );
        }, $this->discord);

        $buttonGreen = Button::new(Button::STYLE_SUCCESS)->setLabel("G +{$amountBet}")->setListener(function (Interaction $interactionUser) use ($interaction, $rouletteId, $roulette, $amountBet, &$gameData) {
            $fromDiscordId = $interactionUser->member->user->id;
            $userDiscord = $interactionUser->member->user;

            $this->apostarRoleta(
                $fromDiscordId,
                Roulette::GREEN,
                $rouletteId,
                $interaction,
                $roulette,
                $amountBet,
                $gameData,
                $userDiscord,
                $interactionUser
            );
        }, $this->discord);

        $buttonBlack = Button::new(Button::STYLE_SECONDARY)->setLabel("BL +{$amountBet}")->setListener(function (Interaction $interactionUser) use ($interaction, $rouletteId, $roulette, $amountBet, &$gameData) {
            $fromDiscordId = $interactionUser->member->user->id;
            $userDiscord = $interactionUser->member->user;

            $this->apostarRoleta(
                $fromDiscordId,
                Roulette::BLACK,
                $rouletteId,
                $interaction,
                $roulette,
                $amountBet,
                $gameData,
                $userDiscord,
                $interactionUser
            );
        }, $this->discord);

        $action->addComponent($buttonRed);
        $action->addComponent($buttonGreen);
        $action->addComponent($buttonBlack);

        $embed = $this->buildEmbedForRoulette($rouletteId, $roulette, $gameData);

        $builder = new MessageBuilder();
        $builder->addEmbed($embed);
        $builder->addComponent($action);

        $interaction->respondWithMessage($builder, false);
    }

    public function apostarRoleta(
        $userDiscordId,
        $choice,
        $rouletteId,
        Interaction $interaction,
        $roulette,
        $amountBet,
        &$gameData,
        $userDiscord,
        Interaction $interactionUser
    ) {
        $roulette = $this->rouletteRepository->getRouletteById($rouletteId);

        if (!$this->userRepository->userExistByDiscordId($userDiscordId)) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Você ainda não coleteu suas coins iniciais! Digita **/coins** e pegue suas coins! :coin::coin::coin: '), true);
            return;
        }

        if ($roulette[0]['status'] !== $this->rouletteRepository::OPEN) {
            $interactionUser->respondWithMessage(MessageBuilder::new()->setContent('Roleta precisa estar aberta para apostar!'), true);
            return;
        }

        $user = $this->userRepository->getByDiscordId($userDiscordId);
        $userId = $user[0]['id'];

        if (!$this->userRepository->hasAvailableCoins($userDiscordId, $amountBet)) {
            $interactionUser->respondWithMessage(MessageBuilder::new()->setContent('Você não possui coins suficientes! :crying_cat_face:'), true);
            return;
        }

        if ($choice == Roulette::RED) {
            $gameData->AmountRed = $gameData->AmountRed + $amountBet;
        } elseif ($choice == Roulette::GREEN) {
            $gameData->AmountGreen = $gameData->AmountGreen + $amountBet;
        } else {
            $gameData->AmountBlack = $gameData->AmountBlack + $amountBet;
        }

        $gameData->AmountTotal = $gameData->AmountTotal + $amountBet;
        $player = new Player($userId, $amountBet, $choice, $userDiscord);
        $playerFound = false;

        foreach ($gameData->jogadores as &$existingPlayer) {
            if ($existingPlayer->user == $userId && $existingPlayer->choice === $choice) {
                $existingPlayer->bet = $existingPlayer->bet + $amountBet;
                $playerFound = true;
                break;
            }
        }

        if (!$playerFound) {
            $gameData->jogadores[] = $player;
        }

        $serializado = serialize($gameData);
        $this->redis->set("roulette:{$rouletteId}", $serializado);

        if ($this->rouletteBetRepository->createRouletteBet($userId, $rouletteId, $amountBet, $choice)) {
            $embed = $this->buildEmbedForRoulette($rouletteId, $roulette, $gameData);

            $builder = new MessageBuilder();
            $builder->addEmbed($embed);
            $interaction->updateOriginalResponse($builder);
        }
    }

    public function buildEmbedForRoulette($rouletteId, $roulette, GameData &$gameData)
    {
        $playersRed = array_filter($gameData->jogadores, function ($player) {
            return $player->choice == Roulette::RED;
        });

        $playersGreen = array_filter($gameData->jogadores, function ($player) {
            return $player->choice == Roulette::GREEN;
        });

        $playersBlack = array_filter($gameData->jogadores, function ($player) {
            return $player->choice == Roulette::BLACK;
        });

        $embed = new Embed($this->discord);
        $embed->setTitle("APOSTEM NA ROLETA 💰\n**[{$rouletteId}]** {$roulette[0]['description']}")
            ->setColor(0x00ff00)
            ->setDescription("Total: {$gameData->AmountTotal}")
            ->setFooter("Últimos giros:\n" . $this->buildLastRoulettesChoices());

        $embed->addFieldValues('🟥 RED 2x', '', true)
            ->addFieldValues('🟩 GREEN 14x', '', true)
            ->addFieldValues('⬛ BLACK 2x', '', true);

        $embed->addFieldValues(
            '',
            implode("\n", array_map(function ($player) {
                return "{$player->userName}\nBet: {$player->bet}";
            }, $playersRed)),
            true
        );

        $embed->addFieldValues(
            '',
            implode("\n", array_map(function ($player) {
                return "{$player->userName}\nBet: {$player->bet}";
            }, $playersGreen)),
            true
        );

        $embed->addFieldValues(
            '',
            implode("\n", array_map(function ($player) {
                return "{$player->userName}\nBet: {$player->bet}";
            }, $playersBlack)),
            true
        );

        return $embed;
    }

    public function buildLastRoulettesChoices(): string
    {
        $lastRoulettes = $this->rouletteRepository->listEventsPaid(15);

        $choices = array_map(function ($arr) {
            return match ($arr['choice']) {
                Roulette::GREEN => "🟩",
                Roulette::BLACK => "⬛",
                Roulette::RED => "🟥"
            };
        }, $lastRoulettes);

        return '➡' . implode(' ', $choices);
    }
}
