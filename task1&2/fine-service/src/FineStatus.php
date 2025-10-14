<?php
namespace App;

/**
 * Enumeration (fixed set of named constants) FineStatus
 * 
 * Represents the possible statuses of a fine
 */
enum FineStatus: string {
    case PAID = 'paid';
    case UNPAID = 'unpaid';
    case OVERDUE = 'overdue';
}