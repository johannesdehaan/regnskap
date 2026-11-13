<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\BudgetLineController;
use App\Http\Controllers\Api\FoodBudgetController;
use App\Http\Controllers\Api\FoodRuleController;
use App\Http\Controllers\Api\HoldingController;
use App\Http\Controllers\Api\HousingPlanController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MonthlyFigureController;
use App\Http\Controllers\Api\OverviewController;
use App\Http\Controllers\Api\RecurringCostController;
use App\Http\Controllers\Api\Sparebank1Controller;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TransactionImportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', MeController::class);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::post('/transactions/import', TransactionImportController::class);
    Route::patch('/transactions/{transaction}', [TransactionController::class, 'update']);

    Route::get('/budget-lines', [BudgetLineController::class, 'index']);
    Route::post('/budget-lines', [BudgetLineController::class, 'store']);
    Route::patch('/budget-lines/{budgetLine}', [BudgetLineController::class, 'update']);
    Route::delete('/budget-lines/{budgetLine}', [BudgetLineController::class, 'destroy']);

    Route::get('/monthly-figures', [MonthlyFigureController::class, 'index']);
    Route::post('/monthly-figures', [MonthlyFigureController::class, 'store']);
    Route::patch('/monthly-figures/{monthlyFigure}', [MonthlyFigureController::class, 'update']);
    Route::delete('/monthly-figures/{monthlyFigure}', [MonthlyFigureController::class, 'destroy']);

    Route::get('/recurring-costs', [RecurringCostController::class, 'index']);
    Route::post('/recurring-costs', [RecurringCostController::class, 'store']);
    Route::patch('/recurring-costs/{recurringCost}', [RecurringCostController::class, 'update']);
    Route::delete('/recurring-costs/{recurringCost}', [RecurringCostController::class, 'destroy']);

    Route::get('/holdings', [HoldingController::class, 'index']);
    Route::post('/holdings', [HoldingController::class, 'store']);
    Route::patch('/holdings/{holding}', [HoldingController::class, 'update']);
    Route::delete('/holdings/{holding}', [HoldingController::class, 'destroy']);

    Route::get('/housing-plans', [HousingPlanController::class, 'index']);
    Route::post('/housing-plans', [HousingPlanController::class, 'store']);
    Route::patch('/housing-plans/{housingPlan}', [HousingPlanController::class, 'update']);
    Route::delete('/housing-plans/{housingPlan}', [HousingPlanController::class, 'destroy']);

    Route::get('/accounts', [AccountController::class, 'index']);
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::patch('/accounts/{account}', [AccountController::class, 'update']);
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy']);

    Route::get('/sparebank1', [Sparebank1Controller::class, 'show']);
    Route::post('/sparebank1/sync', [Sparebank1Controller::class, 'sync']);
    Route::patch('/sparebank1/accounts/{bankAccount}', [Sparebank1Controller::class, 'updateAccount']);

    Route::get('/overview', OverviewController::class);

    Route::get('/food-budget', [FoodBudgetController::class, 'show']);
    Route::patch('/food-budget', [FoodBudgetController::class, 'update']);

    Route::get('/food-rules', [FoodRuleController::class, 'index']);
    Route::post('/food-rules', [FoodRuleController::class, 'store']);
    Route::patch('/food-rules/{foodRule}', [FoodRuleController::class, 'update']);
    Route::delete('/food-rules/{foodRule}', [FoodRuleController::class, 'destroy']);
});
