<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_item_id')->constrained()->cascadeOnDelete();
            $table->string('balance', 12);
            $table->decimal('amount', 18, 2);
            $table->nullableMorphs('source');
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['budget_item_id', 'balance']);
        });

        Schema::create('budget_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('period');
            $table->string('status', 10)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();
        });

        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->string('budget_release', 10)->default('period')->after('budget_control');
        });

        $now = now();
        foreach (DB::table('budget_items as i')->join('budgets as b', 'b.id', '=', 'i.budget_id')->whereIn('b.status', ['approved', 'closed'])
            ->select('i.id', 'i.company_id', 'i.revised_amount')->get() as $item) {
            DB::table('budget_entries')->insert(['company_id' => $item->company_id, 'budget_item_id' => $item->id, 'balance' => 'approved', 'amount' => $item->revised_amount,
                'note' => 'Opening balance', 'created_at' => $now]);
        }
        foreach (DB::table('budget_commitments')->where('status', 'reserved')->get() as $c) {
            DB::table('budget_entries')->insert(['company_id' => $c->company_id, 'budget_item_id' => $c->budget_item_id, 'balance' => 'reserved', 'amount' => $c->amount,
                'source_type' => $c->source_type, 'source_id' => $c->source_id, 'note' => 'Opening balance', 'created_at' => $now]);
        }
        foreach (DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('budget_items as i', 'i.id', '=', 'l.budget_item_id')
            ->join('accounts as a', 'a.id', '=', 'i.account_id')->whereIn('e.status', ['posted', 'reversed'])
            ->groupBy('l.budget_item_id', 'i.company_id', 'a.type')->selectRaw('l.budget_item_id, i.company_id, a.type, SUM(l.debit) as d, SUM(l.credit) as c')->get() as $s) {
            DB::table('budget_entries')->insert(['company_id' => $s->company_id, 'budget_item_id' => $s->budget_item_id, 'balance' => 'spent',
                'amount' => $s->type === 'revenue' ? $s->c - $s->d : $s->d - $s->c, 'note' => 'Opening balance', 'created_at' => $now]);
        }
        Schema::dropIfExists('budget_commitments');

        foreach (DB::table('companies')->pluck('id') as $company) {
            if (DB::table('workflow_templates')->where('company_id', $company)->where('process', 'budget-release')->exists()) {
                continue;
            }
            $role = DB::table('roles')->where('company_id', $company)->where('name', 'Chief Accountant')->value('id')
                ?? DB::table('roles')->where('company_id', $company)->where('name', 'company-admin')->value('id');
            if (! $role) {
                continue;
            }
            $template = DB::table('workflow_templates')->insertGetId(['company_id' => $company, 'process' => 'budget-release', 'company_branch_id' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('workflow_steps')->insert(['workflow_template_id' => $template, 'step_order' => 1, 'name' => 'Finance', 'approver_type' => 'role', 'role_id' => $role,
                'same_branch' => false, 'allow_send_back' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('workflow_templates')->where('process', 'budget-release')->delete();
        Schema::create('budget_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_item_id')->constrained()->cascadeOnDelete();
            $table->morphs('source');
            $table->decimal('amount', 18, 2);
            $table->string('status', 10)->default('reserved');
            $table->timestamps();
        });
        Schema::table('accounting_settings', fn (Blueprint $table) => $table->dropColumn('budget_release'));
        Schema::dropIfExists('budget_releases');
        Schema::dropIfExists('budget_entries');
    }
};
