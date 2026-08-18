<?php

namespace App\Modules\Construction\Filament\Resources\Jobs;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Construction\Filament\Resources\Jobs\Pages\CreateJob;
use App\Modules\Construction\Filament\Resources\Jobs\Pages\EditJob;
use App\Modules\Construction\Filament\Resources\Jobs\Pages\ListJobs;
use App\Modules\Construction\Filament\Resources\Jobs\Schemas\JobForm;
use App\Modules\Construction\Filament\Resources\Jobs\Tables\JobsTable;
use App\Modules\Construction\Models\Job;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Jobs and sites.
 *
 * The label is `Job` by default and per-tenant renameable to Site, Contract or Works (§1.1) — a Gulf
 * contractor says "project", a UK subcontractor says "site", a US GC says "job", and arguing about it is not
 * worth a code change. The rename is a later company setting; the model name never changes.
 *
 * `$navigationGroup = 'Construction'` and that group is claimed for the **construction** domain in this
 * module's `module.php`. Both halves are needed: an unclaimed group belongs to no domain, appears in no
 * column, and everything in it is reachable only by URL.
 */
class JobResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Job::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name', 'site_city'];
    }

    /**
     * Live jobs first, then by code.
     *
     * No row scoping yet: `JobAccess` and the site-engineer filter of §1 arrive with the job team, and adding
     * an unscoped query now is what a later filter replaces rather than something it has to undo.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('client');
    }

    public static function form(Schema $schema): Schema
    {
        return JobForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JobsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobs::route('/'),
            'create' => CreateJob::route('/create'),
            'edit' => EditJob::route('/{record}/edit'),
        ];
    }
}
