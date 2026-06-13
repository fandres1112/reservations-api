<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('restrict');
                
            $table->foreignId('service_id')
                ->constrained('services')
                ->onDelete('restrict');
                
            $table->foreignId('professional_id')
                ->constrained('professionals')
                ->onDelete('restrict');
                
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            
            $table->string('status')->default('active');
            
            $table->dateTime('cancelled_at')->nullable();
            
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('restrict');
                
            $table->decimal('refund_amount', 10, 2)->nullable();
            
            $table->timestamps();

            // Índices de rendimiento y lógica de negocio
            $table->index(['professional_id', 'status', 'start_time', 'end_time'], 'reservations_overlap_idx');
            $table->index(['user_id', 'status', 'start_time'], 'reservations_user_limit_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
