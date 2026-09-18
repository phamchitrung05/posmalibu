<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\OrderItems\OrderItemResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\ProductGroups\Pages\ListProductGroups;
use App\Filament\Resources\ProductGroups\ProductGroupResource;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Stores\Pages\ListStores;
use App\Filament\Resources\Stores\StoreResource;
use App\Filament\Resources\TableSessions\Pages\ListTableSessions;
use App\Filament\Resources\TableZones\Pages\ListTableZones;
use App\Filament\Resources\TableZones\TableZoneResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use App\Models\TableSession;
use App\Models\TableZone;
use App\Models\User;
use App\Queries\Pos\TableMapReadModel;
use App\Queries\Pos\TableSessionActivityReadModel;
use App\Queries\Pos\TableSessionDetailReadModel;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kiểm tra xuyên suốt ranh giới tenant và quyền owner/staff.
 *
 * Các test dùng DatabaseSeeder thật để đồng thời bảo vệ cấu hình role,
 * permission, dữ liệu mẫu và cách Filament nhận diện Store hiện tại.
 */
class StoreTenancyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Tạo lại đầy đủ role, permission và dữ liệu hai chi nhánh trước mỗi test. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    /** Owner phải truy cập được mọi Store, còn staff chỉ truy cập Store đã gán. */
    public function test_owner_and_staff_receive_the_correct_tenant_lists(): void
    {
        $panel = filament()->getPanel('admin');
        $owner = $this->owner();
        $staff = $this->staff();
        $otherStore = Store::query()->whereKeyNot($staff->store_id)->firstOrFail();

        $this->assertCount(2, $owner->getTenants($panel));
        $this->assertCount(1, $staff->getTenants($panel));
        $this->assertTrue($owner->canAccessTenant($otherStore));
        $this->assertFalse($staff->canAccessTenant($otherStore));
        $this->assertTrue($staff->canAccessTenant($staff->store));
    }

    /** Policy phải kết hợp permission với store_id thay vì chỉ kiểm tra role. */
    public function test_model_policy_blocks_staff_from_records_in_another_store(): void
    {
        $owner = $this->owner();
        $staff = $this->staff();
        $ownProduct = Product::query()->where('store_id', $staff->store_id)->firstOrFail();
        $otherProduct = Product::query()->where('store_id', '!=', $staff->store_id)->firstOrFail();

        $this->assertTrue($owner->can('update', $otherProduct));
        $this->assertTrue($staff->can('update', $ownProduct));
        $this->assertFalse($staff->can('update', $otherProduct));

        // Staff không có permission quản trị Store hoặc User, dù hai model
        // này vẫn tồn tại trong cùng panel với các resource nghiệp vụ.
        $this->assertFalse($staff->can('viewAny', Store::class));
        $this->assertFalse($staff->can('viewAny', User::class));
        $this->assertTrue($owner->can('viewAny', Store::class));
        $this->assertTrue($owner->can('create', User::class));
    }

    /** Owner có quyền cập nhật mọi model được quản lý trong Filament. */
    public function test_owner_can_update_every_managed_model(): void
    {
        $owner = $this->owner();
        $models = [
            Store::query()->firstOrFail(),
            User::query()->whereNotNull('store_id')->firstOrFail(),
            TableZone::query()->firstOrFail(),
            DiningTable::query()->firstOrFail(),
            TableSession::query()->firstOrFail(),
            ProductGroup::query()->firstOrFail(),
            Product::query()->firstOrFail(),
            Order::query()->firstOrFail(),
            OrderItem::query()->firstOrFail(),
            Payment::query()->firstOrFail(),
            Printer::query()->firstOrFail(),
            PrintJob::query()->firstOrFail(),
        ];

        foreach ($models as $model) {
            $this->assertTrue($owner->can('update', $model));
        }
    }

    /** Dòng món vẫn có resource nội bộ nhưng không xuất hiện như một trang độc lập trên sidebar. */
    public function test_order_items_are_hidden_from_navigation(): void
    {
        $this->assertFalse(OrderItemResource::shouldRegisterNavigation());
    }

    /** Bảng đơn mở modal xem và bảng thanh toán hiển thị mã đơn nghiệp vụ. */
    public function test_order_and_payment_tables_use_the_expected_read_actions(): void
    {
        $owner = $this->owner();
        $store = Store::query()->firstOrFail();
        $order = Order::query()->where('store_id', $store->getKey())->firstOrFail();
        $payment = Payment::query()->where('store_id', $store->getKey())->with('order')->firstOrFail();

        $this->actingAs($owner);
        Filament::setTenant($store, isQuiet: true);

        Livewire::test(ListOrders::class)
            ->assertTableActionExists('view')
            ->assertTableActionDoesNotExist('edit')
            ->mountTableAction('view', $order->getKey())
            ->assertActionMounted(TestAction::make('view')->table($order));

        Livewire::test(ListPayments::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableColumnStateSet('order.code', $payment->order->code, $payment);

        $selectedTable = app(TableMapReadModel::class)->orderDetails($order);
        $events = app(TableSessionActivityReadModel::class)->for($order->tableSession);
        $modalData = compact('selectedTable', 'events');
        $modalHtml = view('filament.resources.orders.view-modal', $modalData)->render();
        $mobileModalHtml = view('filament.resources.orders.view-modal-mobile', $modalData)->render();

        $this->assertStringContainsString('Danh sách món', $modalHtml);
        $this->assertStringContainsString($order->code, $modalHtml);
        $this->assertStringContainsString('Nhật ký thao tác', $modalHtml);
        $this->assertStringNotContainsString('Thêm món', $modalHtml);
        $this->assertStringContainsString('data-order-view-mobile', $modalHtml);
        $this->assertStringContainsString("x-on:click=\"tab = 'history'\"", $modalHtml);
        $this->assertStringContainsString($selectedTable['name'], $mobileModalHtml);
        $this->assertStringContainsString($selectedTable['zone']['name'], $mobileModalHtml);
        $this->assertStringContainsString($order->code, $mobileModalHtml);
        $this->assertStringContainsString($selectedTable['order']['items'][0]['name'], $mobileModalHtml);
        $this->assertStringContainsString($selectedTable['order']['totalLabel'], $mobileModalHtml);
        $this->assertStringNotContainsString('ORD-091526-001', $mobileModalHtml);
    }

    /** Danh mục tạo bằng modal, còn giao dịch POS không được tạo trực tiếp từ Resource. */
    public function test_catalog_create_actions_use_modals_and_transaction_create_actions_are_absent(): void
    {
        $owner = $this->owner();
        $store = Store::query()->firstOrFail();

        $this->actingAs($owner);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($store, isQuiet: true);

        Livewire::test(ListTableZones::class)
            ->assertActionExists('create')
            ->mountAction('create')
            ->assertActionMounted('create')
            ->assertMountedActionModalSee('Tên khu vực')
            ->fillForm(['name' => 'Khu vực tạo từ modal'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        Livewire::test(ListProductGroups::class)
            ->assertActionExists('create')
            ->mountAction('create')
            ->assertActionMounted('create')
            ->assertMountedActionModalSee('Tên nhóm')
            ->fillForm(['name' => 'Nhóm tạo từ modal'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        Livewire::test(ListStores::class)
            ->assertActionExists('create')
            ->mountAction('create')
            ->assertActionMounted('create')
            ->assertMountedActionModalSee('Tên chi nhánh')
            ->fillForm(['name' => 'Chi nhánh tạo từ modal'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('table_zones', [
            'store_id' => $store->id,
            'name' => 'Khu vực tạo từ modal',
        ]);
        $this->assertDatabaseHas('product_group', [
            'store_id' => $store->id,
            'name' => 'Nhóm tạo từ modal',
        ]);
        $this->assertDatabaseHas('store', ['name' => 'Chi nhánh tạo từ modal']);

        Livewire::test(ListOrders::class)->assertActionDoesNotExist('create');
        Livewire::test(ListPayments::class)->assertActionDoesNotExist('create');

        foreach ([
            TableZoneResource::class,
            ProductGroupResource::class,
            StoreResource::class,
            OrderResource::class,
            PaymentResource::class,
        ] as $resource) {
            $this->assertArrayNotHasKey('create', $resource::getPages());
        }

        $this->assertFalse(OrderResource::canCreate());
        $this->assertFalse(PaymentResource::canCreate());
    }

    /** Bốn danh mục chính chỉnh sửa trong modal thay vì điều hướng sang page riêng. */
    public function test_catalog_edit_actions_use_modals(): void
    {
        $owner = $this->owner();
        $store = Store::query()->firstOrFail();
        $zone = TableZone::query()->where('store_id', $store->id)->firstOrFail();
        $group = ProductGroup::query()->where('store_id', $store->id)->firstOrFail();
        $product = Product::query()->where('store_id', $store->id)->firstOrFail();

        $this->actingAs($owner);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($store, isQuiet: true);

        Livewire::test(ListTableZones::class)
            ->mountTableAction('edit', $zone->getKey())
            ->assertActionMounted(TestAction::make('edit')->table($zone))
            ->assertMountedActionModalSee('Tên khu vực');

        Livewire::test(ListProductGroups::class)
            ->mountTableAction('edit', $group->getKey())
            ->assertActionMounted(TestAction::make('edit')->table($group))
            ->assertMountedActionModalSee('Tên nhóm');

        Livewire::test(ListProducts::class)
            ->mountTableAction('edit', $product->getKey())
            ->assertActionMounted(TestAction::make('edit')->table($product))
            ->assertMountedActionModalSee('Tên món');

        Livewire::test(ListStores::class)
            ->mountTableAction('edit', $store->getKey())
            ->assertActionMounted(TestAction::make('edit')->table($store))
            ->assertMountedActionModalSee('Tên chi nhánh');

        foreach ([
            TableZoneResource::class,
            ProductGroupResource::class,
            ProductResource::class,
            StoreResource::class,
        ] as $resource) {
            $this->assertArrayNotHasKey('edit', $resource::getPages());
        }
    }

    /** Modal phiên bàn dùng ViewAction và shell có một vùng cuộn nội dung rõ ràng. */
    public function test_table_session_view_modal_uses_the_detail_layout(): void
    {
        $owner = $this->owner();
        $store = Store::query()->firstOrFail();
        $session = TableSession::query()
            ->where('store_id', $store->getKey())
            ->with('table')
            ->firstOrFail();

        $this->actingAs($owner);
        Filament::setTenant($store, isQuiet: true);

        Livewire::test(ListTableSessions::class)
            ->assertTableActionExists('view')
            ->assertTableActionDoesNotExist('edit')
            ->mountTableAction('view', $session->getKey())
            ->assertActionMounted(TestAction::make('view')->table($session));

        $details = app(TableSessionDetailReadModel::class)->for($session);
        $modalHtml = view('filament.resources.table-sessions.view-modal', [
            'details' => $details,
        ])->render();

        $this->assertSame($session->table->name, $details['table_name']);
        $this->assertStringNotContainsString('overflow-y-auto', $modalHtml);
        $this->assertStringContainsString('Nhật ký thao tác', $modalHtml);
    }

    /** Middleware Filament phải trả 404 khi staff đoán tenant ID trên URL. */
    public function test_staff_cannot_open_another_store_by_changing_the_url(): void
    {
        $staff = $this->staff();
        $otherStore = Store::query()->whereKeyNot($staff->store_id)->firstOrFail();

        $this->actingAs($staff)
            ->get(ProductResource::getUrl(panel: 'admin', tenant: $otherStore))
            ->assertNotFound();

        $this->actingAs($staff)
            ->get(ProductResource::getUrl(panel: 'admin', tenant: $staff->store))
            ->assertOk();
    }

    /** Owner phải mở được resource của từng chi nhánh qua tenant switcher. */
    public function test_owner_can_open_every_store(): void
    {
        $owner = $this->owner();

        foreach (Store::query()->get() as $store) {
            $this->actingAs($owner)
                ->get(ProductResource::getUrl(panel: 'admin', tenant: $store))
                ->assertOk();
        }
    }

    /** Chỉ owner được mở màn hình quản trị Store và tạo staff cho tenant đang chọn. */
    public function test_only_owner_can_open_store_and_user_management_pages(): void
    {
        $owner = $this->owner();
        $staff = $this->staff();
        $tenant = $staff->store;

        $this->actingAs($owner)
            ->get(StoreResource::getUrl(panel: 'admin', tenant: $tenant))
            ->assertOk();
        $this->actingAs($owner)
            ->get(UserResource::getUrl(panel: 'admin', tenant: $tenant))
            ->assertOk();

        $this->actingAs($staff)
            ->get(UserResource::getUrl(panel: 'admin', tenant: $tenant))
            // Filament chuyển người dùng về trang hợp lệ thay vì render
            // resource không có quyền viewAny.
            ->assertRedirect();
    }

    /** Bảng user của owner phải hiển thị tài khoản thuộc tất cả chi nhánh. */
    public function test_owner_can_see_all_users_in_the_global_user_table(): void
    {
        $owner = $this->owner();
        $tenant = Store::query()->firstOrFail();

        $response = $this->actingAs($owner)
            ->get(UserResource::getUrl(panel: 'admin', tenant: $tenant));

        $response->assertOk();

        foreach (User::query()->pluck('name') as $userName) {
            $response->assertSee($userName);
        }
    }

    /** Owner tạo staff qua modal Filament phải tự gán tenant và giữ đúng vai trò đã chọn. */
    public function test_owner_can_create_a_staff_user_for_the_selected_store(): void
    {
        $owner = $this->owner();
        $tenant = Store::query()->whereKeyNot($this->staff()->store_id)->firstOrFail();
        $staffRole = Role::findByName(UserRole::Staff->value);

        $this->actingAs($owner);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(ListUsers::class)
            ->assertActionExists('create')
            ->mountAction('create')
            ->assertActionMounted('create')
            ->assertMountedActionModalSee('Họ và tên')
            ->fillForm([
                'name' => 'Nhân viên mới',
                'email' => 'new.staff@example.com',
                'password' => 'password',
                'roles' => [$staffRole->id],
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $createdUser = User::query()->where('email', 'new.staff@example.com')->firstOrFail();

        $this->assertSame($tenant->id, $createdUser->store_id);
        $this->assertTrue($createdUser->hasRole(UserRole::Staff->value));
        $this->assertFalse($createdUser->hasRole(UserRole::Owner->value));
    }

    /** Owner được phép tạo thêm tài khoản owner khác từ modal. */
    public function test_owner_can_create_another_owner_via_modal(): void
    {
        $owner = $this->owner();
        $tenant = Store::query()->firstOrFail();
        $ownerRole = Role::findByName(UserRole::Owner->value);

        $this->actingAs($owner);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(ListUsers::class)
            ->mountAction('create')
            ->fillForm([
                'name' => 'Quản trị viên mới',
                'email' => 'new.owner@example.com',
                'password' => 'password',
                'roles' => [$ownerRole->id],
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $createdUser = User::query()->where('email', 'new.owner@example.com')->firstOrFail();

        $this->assertTrue($createdUser->hasRole(UserRole::Owner->value));
        $this->assertTrue($createdUser->canAccessPanel(Filament::getPanel('admin')));
    }

    /** Owner không được tự đổi vai trò của chính mình từ modal edit. */
    public function test_owner_cannot_change_own_role_via_modal(): void
    {
        $owner = $this->owner();
        $tenant = $owner->store ?? Store::query()->firstOrFail();
        $staffRole = Role::findByName(UserRole::Staff->value);

        $this->actingAs($owner);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(ListUsers::class)
            ->mountTableAction('edit', $owner->getKey())
            ->assertActionMounted(TestAction::make('edit')->table($owner))
            ->assertFormFieldDisabled('roles')
            ->fillForm(['name' => 'Chủ cửa hàng đã đổi tên'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $owner->refresh();
        $this->assertSame('Chủ cửa hàng đã đổi tên', $owner->name);
        $this->assertTrue($owner->hasRole(UserRole::Owner->value));
        $this->assertFalse($owner->hasRole($staffRole->name));
    }

    /** Owner vẫn chỉnh sửa được vai trò của tài khoản khác. */
    public function test_owner_can_promote_staff_to_owner_via_modal(): void
    {
        $owner = $this->owner();
        $staff = $this->staff();
        $ownerRole = Role::findByName(UserRole::Owner->value);

        $this->actingAs($owner);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($staff->store, isQuiet: true);

        Livewire::test(ListUsers::class)
            ->mountTableAction('edit', $staff->getKey())
            ->assertFormFieldEnabled('roles')
            ->fillForm(['roles' => [$ownerRole->id]])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $staff->refresh();
        $this->assertTrue($staff->hasRole(UserRole::Owner->value));
    }

    /** Owner không thấy nút xóa chính mình trong bảng tài khoản. */
    public function test_owner_cannot_delete_self(): void
    {
        $owner = $this->owner();
        $staff = $this->staff();
        $tenant = $owner->store ?? Store::query()->firstOrFail();

        $this->actingAs($owner);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('delete', $owner)
            ->assertTableActionVisible('delete', $staff);
    }

    /** Bảng Filament chỉ được render sản phẩm thuộc tenant trên request hiện tại. */
    public function test_filament_resource_table_is_scoped_to_the_current_store(): void
    {
        $staff = $this->staff();
        $ownProduct = Product::query()->where('store_id', $staff->store_id)->firstOrFail();
        $otherProduct = Product::query()->where('store_id', '!=', $staff->store_id)->firstOrFail();

        // Dùng tên không thể là tiền tố của sản phẩm khác; ví dụ "Nước cam 1"
        // trước đây làm assertDontSee() khớp nhầm với chuỗi "Nước cam 10".
        $otherProduct->forceFill(['name' => 'Sản phẩm tenant khác duy nhất'])->save();

        $this->actingAs($staff)
            ->get(ProductResource::getUrl(panel: 'admin', tenant: $staff->store))
            ->assertOk()
            ->assertSee($ownProduct->name)
            ->assertDontSee($otherProduct->name);
    }

    /** Model event phải tự lấy Store từ quan hệ cha khi code chạy ngoài Filament. */
    public function test_model_derives_store_from_its_parent_relation(): void
    {
        $group = ProductGroup::query()->firstOrFail();

        $product = Product::create([
            'product_group_id' => $group->id,
            'name' => 'Sản phẩm kiểm tra tenant',
            'price' => 50000,
            'is_active' => true,
            'is_sku' => false,
        ]);

        $this->assertSame($group->store_id, $product->store_id);
    }

    /** Model event phải chặn việc ghép sản phẩm và đơn hàng khác Store. */
    public function test_model_rejects_cross_store_relationships(): void
    {
        $order = Order::query()->firstOrFail();
        $foreignProduct = Product::query()->where('store_id', '!=', $order->store_id)->firstOrFail();

        $this->expectException(ValidationException::class);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $foreignProduct->id,
            'quantity' => 1,
            'unit_price' => $foreignProduct->price,
        ]);
    }

    /** Lấy tài khoản owner chuẩn do seeder tạo. */
    private function owner(): User
    {
        return User::query()->where('email', 'owner@example.com')->firstOrFail();
    }

    /** Lấy tài khoản staff chuẩn do seeder tạo. */
    private function staff(): User
    {
        return User::query()->where('email', 'staff.tranphu@example.com')->firstOrFail();
    }
}
