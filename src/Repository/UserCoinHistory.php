<?php

namespace Chorume\Repository;

class UserCoinHistory extends Repository
{
    public function __construct($db)
    {
        parent::__construct($db);
    }

    public function all()
    {
        $result = $this->db->select("SELECT * FROM users_coins_history");

        return $result;
    }

    public function create(int $userId, float $amount, string $type, int $entityId = null)
    {
        $result = $this->db->query(
            "INSERT INTO users_coins_history (user_id, entity_id, amount, type) VALUES (?, ?, ?, ?)",
            [
                [ 'type' => 'i', 'value' => $userId ],
                [ 'type' => 'i', 'value' => $entityId ],
                [ 'type' => 'd', 'value' => $amount ],
                [ 'type' => 's', 'value' => $type ]
            ]
        );

        return $result;
    }

    public function listTop10()
    {
        $result = $this->db->select("
            SELECT
                SUM(uch.amount) AS total_coins,
                u.discord_user_id
            FROM users_coins_history uch
            JOIN users u ON uch.user_id = u.id
            GROUP BY uch.user_id
            ORDER BY total_coins DESC
            LIMIT 10
        ");

        return $result;
    }

    /**
     * do not performs any validation here, so be careful as this method can be used to "steal" coins
     */
    public function transfer(int $fromId, float $amount, int $toId)
    {
        $type = 'Transfer';
        $result = $this->db->query(
            "INSERT INTO users_coins_history (user_id, entity_id, amount, type) VALUES (?, ?, ?, ?), (?, ?, ?, ?)",
            [
                [ 'type' => 'i', 'value' => $fromId ],
                [ 'type' => 'i', 'value' => $toId ],
                [ 'type' => 'd', 'value' => -$amount ],
                [ 'type' => 's', 'value' => $type ],
                [ 'type' => 'i', 'value' => $toId ],
                [ 'type' => 'i', 'value' => $fromId ],
                [ 'type' => 'd', 'value' => $amount ],
                [ 'type' => 's', 'value' => $type ],
            ]
        );

        return $result;
    }

    public function hasAvailableCoins(int $discordUserId, float $amount)
    {
        $result = $this->db->select(
            "SELECT SUM(uch.amount) AS total_coins FROM users_coins_history uch JOIN users u ON u.id = uch.user_id WHERE u.discord_user_id = ?",
            [
                [ 'type' => 'i', 'value' => $discordUserId ]
            ]
        );

        $totalCoins = $result[0]['total_coins'] ?? 0;

        return $totalCoins >= $amount;
    }

    public function reachedMaximumAirplanesToday()
    {
        $result = $this->db->select("
            SELECT
                sum(uch.amount) AS total_coins
            FROM users_coins_history uch
                INNER JOIN users u ON u.id = uch.user_id
            WHERE
                `type` like '%Airplane%'
            AND DATE(uch.created_at) = DATE(NOW())
        ", []);

        $totalCoins = $result[0]['total_coins'] ?? 0;

        return $totalCoins > getenv('LITTLE_AIRPLANES_MAXIMUM_AMOUNT_DAY');
    }
}
