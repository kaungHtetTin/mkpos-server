<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agents', function (Blueprint $t) {
            $t->id();
            $t->string('key', 40)->unique();
            $t->string('name', 100);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('ai_skills', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 100)->unique();
            $t->json('draft');
            $t->string('status', 20)->default('draft');
            $t->boolean('is_required')->default(false);
            $t->unsignedBigInteger('published_version_id')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('ai_skill_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('skill_id')->constrained('ai_skills')->cascadeOnDelete();
            $t->unsignedInteger('number');
            $t->json('content');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('note', 500)->default('');
            $t->timestamp('created_at');
            $t->unique(['skill_id', 'number']);
        });
        Schema::create('ai_agent_skill_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $t->foreignId('skill_id')->constrained('ai_skills')->cascadeOnDelete();
            $t->string('mode', 20)->default('on_demand');
            $t->unsignedInteger('position')->default(100);
            $t->boolean('enabled')->default(true);
            $t->json('conditions');
            $t->unique(['agent_id', 'skill_id']);
            $t->timestamps();
        });
        Schema::create('ai_skill_snapshots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('agent_key', 40);
            $t->string('run_reference', 100)->nullable();
            $t->json('versions');
            $t->timestamp('created_at')->index();
        });
        $now = now('UTC');
        foreach (['wyne_wyne' => 'Wyne Wyne', 'kaung_kaung' => 'Kaung Kaung', 'sales_ai' => 'Sale AI'] as $key => $name) {
            DB::table('ai_agents')->insert(['key' => $key, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }
        // Frozen installation snapshot, never used as a runtime fallback.
        $seed = json_decode(file_get_contents(database_path('seed-data/ai-skills-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($seed as $record) {
            $assignments = $record['assignments'];
            $required = $record['required'];
            $slug = $record['slug'];
            unset($record['assignments'], $record['required'], $record['slug']);
            $record['examples'] = '';
            $content = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $id = DB::table('ai_skills')->insertGetId(['slug' => $slug, 'draft' => $content, 'status' => 'published', 'is_required' => $required, 'created_at' => $now, 'updated_at' => $now]);
            $version = DB::table('ai_skill_versions')->insertGetId(['skill_id' => $id, 'number' => 1, 'content' => $content, 'note' => 'Imported from existing application skills', 'created_at' => $now]);
            DB::table('ai_skills')->where('id', $id)->update(['published_version_id' => $version]);
            foreach ($assignments as $assignment) {
                $agent = DB::table('ai_agents')->where('key', $assignment['agent'])->value('id');
                $conditions = array_intersect_key($assignment, array_flip(['roles', 'channels', 'contexts']));
                DB::table('ai_agent_skill_assignments')->insert(['agent_id' => $agent, 'skill_id' => $id, 'mode' => $assignment['mode'], 'position' => $assignment['position'],
                    'enabled' => true, 'conditions' => json_encode($conditions), 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        foreach (['ai_skill_snapshots', 'ai_agent_skill_assignments', 'ai_skill_versions', 'ai_skills', 'ai_agents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
