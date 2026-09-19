<?php

namespace Modules\Dashboard\DataTables;

use App\Support\Integrations\GeoIp\GeoIpService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Jenssegers\Agent\Agent;
use Modules\Auth\Models\MerchantSession;
use Psy\Readline\Hoa\Console;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class ActiveSessionsDataTable extends DataTable
{
    protected GeoIpService $geoIpService;
    public function __construct(GeoIpService $geoIpService)
    {
        $this->geoIpService = $geoIpService;
    }
    public function dataTable(Builder $query, Request $request): EloquentDataTable
    {
        $currentSessionId = $request->attributes->get('merchant_session')?->id;

        return datatables()
            ->eloquent($query)

            ->addColumn('device', function (MerchantSession $session) {

                $browser = $session->browser ?: 'Unknown Browser';
                $platform = $session->platform ?: 'Unknown OS';

                return sprintf(
                    '<div class="d-flex flex-column">
                    <span class="fw-bold text-gray-800">%s</span>
                    <span class="text-muted fs-7">%s</span>
                </div>',
                    e($browser),
                    e($platform)
                );
            })

            ->addColumn(
                'location',
                fn(MerchantSession $session) =>
                $session->location ?: 'Unknown'
            )

            ->addColumn('status', function (MerchantSession $session) use ($currentSessionId) {

                return $session->id === $currentSessionId
                    ? '<span class="badge badge-light-success">This Device</span>'
                    : '<span class="badge badge-light-primary">Active</span>';
            })

            ->addColumn('action', function (MerchantSession $session) use ($currentSessionId) {

                if ($session->id === $currentSessionId) {
                    return '-';
                }

                return sprintf(
                    '<button
                        type="button"
                        class="btn btn-sm btn-light-danger js-signout-session"
                        data-session-id="%s">
                        Sign Out
                    </button>',
                    $session->id
                );
            })

            ->editColumn(
                'last_activity_at',
                fn(MerchantSession $session) =>
                $session->last_activity_at?->diffForHumans() ?? '-'
            )

            ->rawColumns([
                'device',
                'status',
                'action',
            ]);
    }

    public function query(
        Request $request,
        MerchantSession $model
    ): Builder {

        $clientAccount = $request
            ->attributes
            ->get('client_account');

        return $model
            ->newQuery()
            ->where(
                'client_account_id',
                $clientAccount->id
            )
            ->whereNull('revoked_at')
            // Admin "view as client" (read-only, audited) sessions are an
            // internal support tool, not a device the client signed into —
            // keep them out of the client's own session list. They're still
            // fully recorded in the audit log (IMPERSONATION_STARTED/ENDED).
            ->where('is_impersonation', false)
            ->latest('created_at');
    }

    public function html(): HtmlBuilder
    {
        return $this
            ->builder()
            ->setTableId('active-sessions-table')
            ->columns($this->getColumns())
            ->minifiedAjax()

            ->responsive(true)
            ->autoWidth(false)
            ->stateSave()

            ->addTableClass(
                'table align-middle table-row-dashed fs-6 gy-5'
            )

            ->parameters([
                'language' => [
                    'search' => '',
                    'searchPlaceholder' => 'Search sessions...',
                    'emptyTable' => 'No active sessions found.',
                ],

                'order' => [[4, 'desc']],
            ]);
    }

    protected function getColumns(): array
    {
        return [

            Column::computed('device')
                ->title('Device')
                ->orderable(false),

            Column::computed('location')
                ->title('Location')
                ->orderable(false),

            Column::make('last_activity_at')
                ->title('Last Activity'),

            Column::computed('status')
                ->title('Status')
                ->orderable(false)
                ->searchable(false),

            Column::computed('action')
                ->title('Action')
                ->orderable(false)
                ->searchable(false)
                ->exportable(false)
                ->printable(false),
        ];
    }

    protected function filename(): string
    {
        return 'ActiveSessions_' . now()->format('YmdHis');
    }
}
