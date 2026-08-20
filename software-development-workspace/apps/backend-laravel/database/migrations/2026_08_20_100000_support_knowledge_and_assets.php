<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Answers worth keeping, and the kit they were about.
 *
 * ── Why a knowledge base at all ──
 *
 * There is not one anywhere in the old system — no articles, no FAQ, nothing.
 * Every answer was retyped from memory or found by scrolling somebody's sent
 * items, which is why the same fault produced a different explanation depending
 * on who picked it up.
 *
 * ── Why tickets link to assets ──
 *
 * mdl_assets_issues had a schema and nought rows: for an infrastructure
 * business, no ticket has ever been attached to the equipment it was about. So
 * "what keeps breaking?" has never been answerable, and neither has "what has
 * this switch cost us in call-outs".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('title', 220);
            $table->string('slug', 220);
            $table->string('summary', 400)->nullable();
            $table->longText('body');

            /*
             * draft → in_review → published, and archived.
             *
             * A review step because an article is advice given in the
             * company's name to somebody who cannot judge it. Wrong advice
             * published once is repeated by everybody who finds it.
             */
            $table->string('status', 12)->default('draft');

            /*
             * internal | customer
             *
             * The distinction the portal depends on. An article explaining
             * which supplier to call and what the margin is has a place; that
             * place is not a customer's screen, and a single flag is easier to
             * get right than a permission check at every read.
             */
            $table->string('visibility', 10)->default('internal');

            $table->foreignId('service_category_id')->nullable()
                ->constrained('support_service_categories')->nullOnDelete();

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            /** Where it came from, when somebody wrote it up from a fix. */
            $table->foreignId('source_ticket_id')->nullable()
                ->constrained('support_tickets')->nullOnDelete();

            /*
             * Read and rated. Not vanity: an article nobody opens is one nobody
             * can find, and an article opened often and marked unhelpful is
             * worse than none — it is confidently wrong and being followed.
             */
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('unhelpful_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status', 'visibility']);
        });

        /**
         * An article used on a ticket.
         *
         * How it was used matters. An article an agent attached to an answer
         * did its job; one a customer read and then raised a ticket anyway did
         * not, and only the second tells you the article needs rewriting.
         */
        Schema::create('support_article_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_article_id')->constrained('support_articles')->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->nullable()
                ->constrained('support_tickets')->cascadeOnDelete();

            // attached | suggested | deflected
            $table->string('usage', 12);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['support_article_id', 'usage']);
        });

        /**
         * Which kit a ticket was about.
         *
         * A pivot rather than a column: a link going down takes out a switch, a
         * router and a circuit, and picking one of them as "the" asset loses
         * the other two from every count that follows.
         */
        Schema::create('support_ticket_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            /** primary | affected — what the fault was, versus what it touched. */
            $table->string('relationship', 12)->default('affected');

            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['support_ticket_id', 'asset_id']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_assets');
        Schema::dropIfExists('support_article_links');
        Schema::dropIfExists('support_articles');
    }
};
