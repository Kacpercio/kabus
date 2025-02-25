@extends('layouts.app')
@section('content')
<div class="admin-panel-container">
    <div class="admin-panel-card">
        <h1 class="admin-panel-title">Vendor Panel</h1>
        <p class="admin-panel-welcome">Welcome to the Vendor Panel. Here you can manage your products in {{ config('app.name') }}.</p>     
        <div class="admin-panel-grid">
            <div class="admin-panel-item">
                <div class="admin-panel-item-icon">
                </div>
                <h3 class="admin-panel-item-title">💾 Add Digital Item 💾</h3>
                <p class="admin-panel-item-description">You can add digital products to {{ config('app.name') }}.</p>
                <a href="{{ route('vendor.products.create', 'digital') }}" class="admin-panel-item-link">Add Digital Product</a>
            </div>
            
            <div class="admin-panel-item">
                <div class="admin-panel-item-icon">
                </div>
                <h3 class="admin-panel-item-title">🚚 Add Cargo Item 🚚</h3>
                <p class="admin-panel-item-description">You can add physical products that can be delivered by shipping.</p>
                <a href="{{ route('vendor.products.create', 'cargo') }}" class="admin-panel-item-link">Add Cargo Product</a>
            </div>
            
            <div class="admin-panel-item">
                <div class="admin-panel-item-icon">
                </div>
                <h3 class="admin-panel-item-title">🗺️ Add Dead Drop Item 🗺️</h3>
                <p class="admin-panel-item-description">You can add products that can be delivered via dead drop.</p>
                <a href="{{ route('vendor.products.create', 'deaddrop') }}" class="admin-panel-item-link">Add Dead Drop Product</a>
            </div>
            <div class="admin-panel-item">
                <div class="admin-panel-item-icon">
                </div>
                <h3 class="admin-panel-item-title">🎨 Vendor Appearance 🎨</h3>
                <p class="admin-panel-item-description">You can customize your store appearance and profile.</p>
                <a href="{{ route('vendor.appearance') }}" class="admin-panel-item-link">Edit Appearance</a>
            </div>
            
            <div class="admin-panel-item">
                <div class="admin-panel-item-icon">
                </div>
                <h3 class="admin-panel-item-title">💼 My Products 💼</h3>
                <p class="admin-panel-item-description">You can view all products you have listed for sale on {{ config('app.name') }}.</p>
                <a href="{{ route('vendor.my-products') }}" class="admin-panel-item-link">View My Products</a>
            </div>
            
            <div class="admin-panel-item">
                <div class="admin-panel-item-icon">
                </div>
                <h3 class="admin-panel-item-title">💸 My Sales 💸</h3>
                <p class="admin-panel-item-description">You can view all your completed sales.</p>
                <a href="{{ route('vendor.sales') }}" class="admin-panel-item-link">View My Sales</a>
            </div>

            <div class="admin-panel-item">
                <div class="admin-panel-item-icon">
                </div>
                <h3 class="admin-panel-item-title">❗ My Disputes ⛔</h3>
                <p class="admin-panel-item-description">View and manage customer disputes and resolution cases.</p>
                <a href="#" class="admin-panel-item-link">View Disputes</a>
            </div>
        </div>
    </div>
</div>
@endsection
