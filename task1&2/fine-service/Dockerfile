# Base image
FROM php:8.3-cli

# Install dependencies (PostgreSQL, SQLite, unzip, git)
RUN apt-get update && apt-get install -y \
    libpq-dev \
    sqlite3 \
    libsqlite3-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app
COPY . /app

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install

# Expose port
EXPOSE 8080

# Start PHP built-in server
CMD ["php", "-S", "0.0.0.0:8080", "-t", "src"]



# Usage:

# docker-compose build --no-cache
# docker-compose up

# Rebuild container and start docker server
#    docker-compose up --build &

# Stop docker server
#    docker-compose down

# Test docker locally with CURL:
# Health Check
#    curl http://localhost:8080/

# Create A Fine 
#    curl -X POST http://localhost:8080/fines -H "Content-Type: application/json" -d '{"offender_id": 42, "offender_name": "John Tester", "offence_type": "Parking in no-stopping zone", "fine_amount": 150.50, "reason": "Parking in no-stopping zone", "date_issued": "2025-10-14", "status": "unpaid"}'

# List All Fines
#    curl -X GET http://localhost:8080/fines

# Get a Single Fine
#    curl -X GET http://localhost:8080/fines/1

# Update a Fine
#    curl -X PUT http://localhost:8080/fines/1 -H "Content-Type: application/json" -d '{"offender_id": 42, "offender_name": "John Tester", "offence_type": "Speeding", "fine_amount": 200.00, "reason": "Updated reason: speeding", "date_issued": "2025-10-14", "status": "unpaid"}'

# Partially Update a Fine
#    curl -X PATCH http://localhost:8080/fines/1 -H "Content-Type: application/json" -d '{"fine_amount": 100.00}'

# Mark Fine as Paid
#    curl -X PATCH http://localhost:8080/fines/1/pay

# Delete a Fine
#    curl -X DELETE http://localhost:8080/fines/1