<?php
declare(strict_types=1);
namespace Vereinskiste\Invoices;

use PDO;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Ledger\Transaction;

final class PaymentPolicy
{
    public function __construct(private PDO $pdo) {}
    public function get(bool $lock=false): array
    {
        $row=$this->pdo->query('SELECT four_eyes,version FROM finance_payment_settings WHERE id=1'.($lock?' FOR UPDATE':''))->fetch();
        return ['four_eyes'=>(bool)$row['four_eyes'],'version'=>(int)$row['version']];
    }
    public function save(bool $fourEyes,int $version,int $actor,string $reason): void
    {
        Fields::read(['reason'=>$reason],['reason'=>500],['reason']);
        Transaction::run($this->pdo,function()use($fourEyes,$version,$actor,$reason):void {
            $before=$this->get(true);if($before['version']!==$version) {throw new Conflict();}
            $this->pdo->prepare('UPDATE finance_payment_settings SET four_eyes=?::boolean,version=version+1 WHERE id=1')->execute([$fourEyes?'true':'false']);
            (new History($this->pdo))->record('payment_policy',1,$before,['four_eyes'=>$fourEyes,'version'=>$version+1],$actor,$reason);
        });
    }
}
