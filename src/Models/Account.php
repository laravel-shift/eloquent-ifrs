<?php

/**
 * Eloquent IFRS Accounting
 *
 * @author    Edward Mungai
 * @copyright Edward Mungai, 2020, Germany
 * @license   MIT
 */

namespace IFRS\Models;

use Carbon\Carbon;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use IFRS\Interfaces\Recyclable;
use IFRS\Interfaces\Segregatable;

use IFRS\Traits\Recycling;
use IFRS\Traits\Segregating;
use IFRS\Traits\ModelTablePrefix;

use IFRS\Exceptions\MissingAccountType;
use IFRS\Exceptions\HangingTransactions;
use IFRS\Exceptions\InvalidCategoryType;

/**
 * Class Account
 *
 * @package Ekmungai\Eloquent-IFRS
 *
 * @property Entity $entity
 * @property Category $category
 * @property Currency $currency
 * @property int|null $code
 * @property string $name
 * @property string $description
 * @property string $account_type
 * @property Carbon $destroyed_at
 * @property Carbon $deleted_at
 * @property float $openingBalance
 * @property float $currentBalance
 * @property float $closingBalance
 */
class Account extends Model implements Recyclable, Segregatable
{
    use Segregating;
    use SoftDeletes;
    use Recycling;
    use ModelTablePrefix;

    /**
     * Account Type.
     *
     * @var string
     */

    const NON_CURRENT_ASSET = 'NON_CURRENT_ASSET';
    const CONTRA_ASSET = 'CONTRA_ASSET';
    const INVENTORY = 'INVENTORY';
    const BANK = 'BANK';
    const CURRENT_ASSET = 'CURRENT_ASSET';
    const RECEIVABLE = 'RECEIVABLE';
    const NON_CURRENT_LIABILITY = 'NON_CURRENT_LIABILITY';
    const CONTROL = 'CONTROL';
    const CURRENT_LIABILITY = 'CURRENT_LIABILITY';
    const PAYABLE = 'PAYABLE';
    const EQUITY = 'EQUITY';
    const OPERATING_REVENUE = 'OPERATING_REVENUE';
    const OPERATING_EXPENSE = 'OPERATING_EXPENSE';
    const NON_OPERATING_REVENUE = 'NON_OPERATING_REVENUE';
    const DIRECT_EXPENSE = 'DIRECT_EXPENSE';
    const OVERHEAD_EXPENSE = 'OVERHEAD_EXPENSE';
    const OTHER_EXPENSE = 'OTHER_EXPENSE';
    const RECONCILIATION = 'RECONCILIATION';

    /**
     * Purchaseable Account Types
     *
     * @var array
     */

    const PURCHASABLES = [
        Account::OPERATING_EXPENSE,
        Account::DIRECT_EXPENSE,
        Account::OVERHEAD_EXPENSE,
        Account::OTHER_EXPENSE,
        Account::NON_CURRENT_ASSET,
        Account::CURRENT_ASSET,
        Account::INVENTORY
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'account_type',
        'account_id',
        'currency_id',
        'category_id',
        'description',
        'code',
    ];

    /**
     * Construct new Account.
     */
    public function __construct($attributes = [])
    {
        if (!isset($attributes['currency_id']) && Auth::user()->entity) {
            $attributes['currency_id'] = Auth::user()->entity->currency_id;
        }

        return parent::__construct($attributes);
    }

    /**
     * Get Human Readable Account Type.
     *
     * @param string $type
     *
     * @return string
     */
    public static function getType($type)
    {
        return config('ifrs')['accounts'][$type];
    }

    /**
     * Get Human Readable Account types
     *
     * @param array $types
     *
     * @return array
     */
    public static function getTypes($types)
    {
        $typeNames = [];

        foreach ($types as $type) {
            $typeNames[] = Account::getType($type);
        }
        return $typeNames;
    }

    /**
     * Chart of Account Section Balances for the Reporting Period.
     *
     * @param string $accountType
     * @param string | Carbon $startDate
     * @param string | Carbon $endDate
     *
     * @return array
     */
    public static function sectionBalances(
        array $accountTypes,
        $startDate = null,
        $endDate = null
    ): array {
        $balances = ["sectionTotal" => 0, "sectionCategories" => []];

        $startDate = is_null($startDate) ? ReportingPeriod::periodStart($endDate) : Carbon::parse($startDate);
        $endDate = is_null($endDate) ? Carbon::now() : Carbon::parse($endDate);

        $year = ReportingPeriod::year($endDate);

        foreach (Account::whereIn("account_type", $accountTypes)->get() as $account) {

            $account->openingBalance = $account->openingBalance($year);
            $account->currentBalance = Ledger::balance($account, $startDate, $endDate);
            $account->closingBalance = $account->openingBalance + $account->currentBalance;

            if ($account->closingBalance <> 0) {

                if (is_null($account->category)) {
                    $categoryName =  config('ifrs')['accounts'][$account->account_type];
                    $categoryId = 0;
                } else {
                    $categoryName =  $account->category->name;
                    $categoryId = $account->category->id;
                }

                if (in_array($categoryName, $balances["sectionCategories"])) {
                    $balances["sectionCategories"][$categoryName]['accounts']->push((object) $account->attributes);
                    $balances["sectionCategories"][$categoryName]['total'] += $account->closingBalance;
                } else {
                    $balances["sectionCategories"][$categoryName]['accounts'] = collect([(object) $account->attributes]);
                    $balances["sectionCategories"][$categoryName]['total'] = $account->closingBalance;
                    $balances["sectionCategories"][$categoryName]['id'] = $categoryId;
                }
            }
            $balances["sectionTotal"] += $account->closingBalance;
        }

        return $balances;
    }


    /**
     * Chart of Account Balances movement for the given Period.
     *
     * @param array $accountTypes
     * @param string | carbon $startDate
     * @param string | carbon $endDate
     *
     * @return array
     */

    public static function movement($accountTypes, $startDate = null, $endDate = null)
    {
        $startDate = is_null($startDate) ? ReportingPeriod::periodStart($endDate) : Carbon::parse($startDate);
        $endDate = is_null($endDate) ? Carbon::now() : Carbon::parse($endDate);
        $periodStart = ReportingPeriod::periodStart($endDate);

        $openingBalance = $closingBalance = 0;

        //balance till period start
        $openingBalance += Account::sectionBalances($accountTypes, $periodStart, $startDate)["sectionTotal"];

        //balance till period end
        $closingBalance += Account::sectionBalances($accountTypes, $periodStart, $endDate)["sectionTotal"];

        return ($closingBalance - $openingBalance) * -1;
    }

    /**
     * Instance Type.
     *
     * @return string
     */
    public function getTypeAttribute()
    {
        return Account::getType($this->account_type);
    }

    /**
     * Instance Identifier.
     *
     * @return string
     */
    public function toString($type = false)
    {
        return $type ? $this->type . ': ' . $this->name : $this->name;
    }

    /**
     * Account Currency.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Account Category.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Account Balances.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function balances()
    {
        return $this->hasMany(Balance::class);
    }

    /**
     * Account attributes.
     *
     * @return object
     */
    public function attributes()
    {
        $this->attributes['closingBalance'] = $this->closingBalance(date("Y-m-d"));
        return (object) $this->attributes;
    }

    /**
     * Get Account's Opening Balance for the Reporting Period.
     *
     * @param int $year
     *
     * @return float
     */
    public function openingBalance(int $year = null): float
    {
        if (!is_null($year)) {
            $period = ReportingPeriod::where('calendar_year', $year)->first();
        } else {
            $period = Auth::user()->entity->current_reporting_period;
        }

        $balance = 0;

        foreach ($this->balances->where("reporting_period_id", $period->id) as $record) {
            $amount = $record->amount / $record->exchangeRate->rate;
            $record->balance_type == Balance::DEBIT ? $balance += $amount : $balance -= $amount;
        }
        return $balance;
    }

    /**
     * Get Account's Current Balance for the Period given.
     *
     * @param string $startDate
     * @param string $endDate
     *
     * @return float
     */
    public function currentBalance(string $startDate = null, string $endDate = null): float
    {

        $startDate = is_null($startDate) ? ReportingPeriod::periodStart($endDate) : Carbon::parse($startDate);
        $endDate = is_null($endDate) ? Carbon::now() : Carbon::parse($endDate);
        return Ledger::balance($this, $startDate, $endDate);
    }

    /**
     * Get Account's Closing Balance for the Reporting Period.
     *
     * @param string $endDate
     *
     * @return float
     */
    public function closingBalance(string $endDate = null): float
    {
        $endDate = is_null($endDate) ? Carbon::now() : $endDate;
        $startDate = ReportingPeriod::periodStart($endDate);
        $year = ReportingPeriod::year($endDate);

        return $this->openingBalance($year) + $this->currentBalance($startDate, $endDate);
    }

    /**
     * Get Account's Transactions for the Reporting Period.
     *
     * @param string $startDate
     * @param string $endDate
     *
     * @return array
     */
    public function getTransactions(string $startDate = null, string $endDate = null): array
    {

        $transactions = ["total" => 0, "transactions" => []];
        $startDate = is_null($startDate) ? ReportingPeriod::periodStart($endDate) : Carbon::parse($startDate);
        $endDate = is_null($endDate) ? Carbon::now() : Carbon::parse($endDate);
        $id = $this->id;

        //select all posted transactions having account as main or line item account
        $query = DB::table('ifrs_transactions')
            ->join('ifrs_ledgers', 'ifrs_transactions.id', '=', 'ifrs_ledgers.transaction_id')
            ->select(
                'ifrs_transactions.id',
                'ifrs_transactions.transaction_date',
                'ifrs_transactions.transaction_no',
                'ifrs_transactions.transaction_type',
                'ifrs_ledgers.posting_date'
            )->where(function ($query) use ($id) {
                $query->where("ifrs_ledgers.post_account", $id)
                    ->orwhere("ifrs_ledgers.folio_account", $id);
            })
            ->where("ifrs_ledgers.posting_date", ">=", $startDate)
            ->where("ifrs_ledgers.posting_date", "<=", $endDate)
            ->distinct('ifrs_transactions.id');

        foreach ($query->get() as $transaction) {

            $transaction->amount = abs(Ledger::contribution($this, $transaction->id));
            $transaction->type = Transaction::getType($transaction->transaction_type);
            $transaction->date = Carbon::parse($transaction->transaction_date)->toFormattedDateString();
            $transactions['transactions'][] = $transaction;
            $transactions['total'] += $transaction->amount;
        }
        return $transactions;
    }

    /**
     * Calculate Account Code.
     */
    public function save(array $options = []): bool
    {
        if (is_null($this->code) || $this->isDirty('account_type')) {
            if (is_null($this->account_type)) {
                throw new MissingAccountType();
            }

            $this->code = config('ifrs')['account_codes'][$this->account_type] + Account::withTrashed()
                ->where("account_type", $this->account_type)
                ->count() + 1;
        }

        if (!is_null($this->category) && $this->category->category_type != $this->account_type) {
            throw new InvalidCategoryType($this->account_type, $this->category->category_type);
        }

        $this->name = ucfirst($this->name);
        return parent::save($options);
    }

    /**
     * Check for Current Year Transactions.
     */
    public function delete(): bool
    {
        if ($this->closingBalance() != 0) {
            throw new HangingTransactions();
        }

        return parent::delete();
    }
}
