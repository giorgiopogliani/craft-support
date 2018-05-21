<?php
/**
 * Support plugin for Craft CMS 3.x
 *
 * Simple support system for tracking, prioritising and solving customer support tickets.
 *
 * @link      https://github.com/lukeyouell
 * @copyright Copyright (c) 2018 Luke Youell
 */

namespace lukeyouell\support\services;

use lukeyouell\support\Support;
use lukeyouell\support\elements\Ticket;
use lukeyouell\support\models\Email as EmailModel;
use lukeyouell\support\models\TicketStatus as TicketStatusModel;
use lukeyouell\support\records\Email as EmailRecord;
use lukeyouell\support\records\TicketStatus as TicketStatusRecord;
use lukeyouell\support\records\TicketStatusEmail as TicketStatusEmailRecord;

use Craft;
use craft\base\Component;
use craft\db\Query;

use yii\base\Exception;

class TicketStatusService extends Component
{

    // Properties
    // =========================================================================

    private static $_fetchedAllStatuses = false;

    private static $_ticketStatusesById = [];

    private static $_ticketStatusesByHandle = [];

    // Public Static Methods
    // =========================================================================

    public static function getAllTicketStatuses()
    {
        if (!self::$_fetchedAllStatuses) {
            $results = self::_createTicketStatusQuery()->all();

            foreach ($results as $row) {
                self::_memoizeTicketStatus(new TicketStatusModel($row));
            }

            self::$_fetchedAllStatuses = true;
        }

        return self::$_ticketStatusesById;
    }

    public static function getTicketStatusById($id)
    {
        $result = self::_createTicketStatusQuery()
            ->where(['id' => $id])
            ->one();

        return new TicketStatusModel($result);
    }

    public static function getDefaultTicketStatus()
    {
        $result = self::_createTicketStatusQuery()
            ->where(['default' => 1])
            ->one();

        return new TicketStatusModel($result);
    }

    public static function checkIfTicketStatusInUse($id)
    {
        $result = Ticket::find()
            ->ticketStatusId($id)
            ->one();

        return $result;
    }

    public static function reorderTicketStatuses(array $ids)
    {
        foreach ($ids as $sortOrder => $id) {
            Craft::$app->getDb()->createCommand()
                ->update('{{%support_ticketstatuses}}', ['sortOrder' => $sortOrder + 1], ['id' => $id])
                ->execute();
        }

        return true;
    }

    public static function saveTicketStatus(TicketStatusModel $model, array $emailIds, bool $runValidation = true)
    {
        if ($model->id) {
            $record = TicketStatusRecord::findOne($model->id);

            if (!$record->id) {
                throw new Exception(Craft::t('support', 'No ticket status exists with the ID "{id}"',
                    ['id' => $model->id]));
            }
        } else {
            $record = new TicketStatusRecord();
        }

        if ($runValidation && !$model->validate()) {
            Craft::info('Ticket status not saved due to a validation error.', __METHOD__);

            return false;
        }

        $record->name = $model->name;
        $record->handle = $model->handle;
        $record->colour = $model->colour;
        $record->sortOrder = $model->sortOrder ?: 999;
        $record->default = $model->default;

        // Validate email ids
        $exist = EmailRecord::find()->where(['in', 'id', $emailIds])->exists();
        $hasEmails = (boolean) count($emailIds);

        if (!$exist && $hasEmails) {
            $model->addError('emails', 'One or more emails do not exist in the system.');
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            // Only one default status can be among statuses
            if ($record->default) {
                TicketStatusRecord::updateAll(['default' => 0]);
            }

            // Save it
            $record->save(false);

            // Delete old email links
            if ($model->id) {
                $records = TicketStatusEmailRecord::find()->where(['ticketStatusId' => $model->id])->all();

                foreach ($records as $record) {
                    $record->delete();
                }
            }

            // Save new email links
            $rows = array_map(
                function ($id) use ($record) {
                    return [$id, $record->id];
                }, $emailIds);

            $cols = ['emailId', 'ticketStatusId'];
            $table = TicketStatusEmailRecord::tableName();
            Craft::$app->getDb()->createCommand()->batchInsert($table, $cols, $rows)->execute();

            // Now that we have a record ID, save it on the model
            $model->id = $record->id;

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();

            throw $e;
        }

        return true;
    }

    public static function deleteTicketStatusbyId($id)
    {
        $statuses = self::getAllTicketStatuses();

        $existingTicket = self::checkIfTicketStatusInUse($id);

        // Don't delete if it's still in use
        if ($existingTicket) {
            return false;
        }

        // Don't delete if it's the only status left
        if (count($statuses) > 1) {
            $record = TicketStatusRecord::findOne($id);

            return $record->delete();
        }

        return false;
    }

    // Private Static Methods
    // =========================================================================

    private static function _memoizeTicketStatus(TicketStatusModel $ticketStatus)
    {
        self::$_ticketStatusesById[$ticketStatus->id] = $ticketStatus;
        self::$_ticketStatusesByHandle[$ticketStatus->handle] = $ticketStatus;
    }

    private static function _createTicketStatusQuery()
    {
        return (new Query())
            ->select([
                'id',
                'name',
                'handle',
                'colour',
                'sortOrder',
                'default',
            ])
            ->orderBy('sortOrder')
            ->from(['{{%support_ticketstatuses}}']);
    }
}
