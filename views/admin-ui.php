<?php
    if (!defined('pp_allowed_access')) {
        die('Direct access not allowed');
    }

      global $conn, $setting;
      global $db_host, $db_user, $db_pass, $db_name, $db_prefix, $mode;

      $plugin_slug = 'advance-analytics';
      $settings = pp_get_plugin_setting($plugin_slug);
      $setting = pp_get_settings();
      
      // Set default currency settings if not available
      if (!isset($setting['response'][0]['currency_symbol']) || !isset($setting['response'][0]['default_currency'])) {
          $setting['response'][0]['currency_symbol'] = '৳';
          $setting['response'][0]['default_currency'] = 'BDT';
      }

    require_once __DIR__ . '/../../../../../pp-config.php';

    // Try updating database
    if (!isset($conn)) {
        $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($conn->connect_error) {
            $error = "Connection failed: " . $conn->connect_error;
        }
        if (!$conn->query("SET NAMES utf8")) {
            $error = "Set names failed: " . $conn->error;
        }
        if (!empty($db_prefix)) {
            if (!$conn->query("SET sql_mode = ''")) {
                $error = "Set sql_mode failed: " . $conn->error;
            }
        }
    }

    function convertCurrency($amount, $currencyCode) {
        global $conn, $db_prefix, $setting;
        if ($currencyCode === $setting['response'][0]['default_currency']) return $amount;

        //get default currency value from currency table
        $query = "SELECT currency_rate 
                 FROM " . $db_prefix . "currency 
                 WHERE currency_code = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $setting['response'][0]['default_currency']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if (!$row) return $amount; // If currency not found, return original amount
        
        $rate = floatval($row['currency_rate']);
        
        $defaultCurrencyValue = floatval($row['currency_rate']);
        
        $query = "SELECT currency_rate 
                 FROM " . $db_prefix . "currency 
                 WHERE currency_code = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $currencyCode);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if (!$row) return $amount; // If currency not found, return original amount
        
        $rate = floatval($row['currency_rate']);
        // Convert foreign currency to BDT by multiplying with rate
        // For example: 0.83 USD * 120 BDT/USD = 99.6 BDT
        return ($amount / $rate) * $defaultCurrencyValue;
    }

    // Function to get transaction sum for a specific date range
    function getTransactionSum($startDate, $endDate) {
        global $conn, $db_prefix;
        $table = $db_prefix . "transaction";
        
        $query = "SELECT transaction_amount, transaction_currency 
                 FROM $table 
                 WHERE transaction_status = 'completed' 
                 AND created_at BETWEEN ? AND ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $amount = floatval($row['transaction_amount']);
            $currency = $row['transaction_currency'];
            $bdtAmount = convertCurrency($amount, $currency);
            $total += $bdtAmount;
        }
        
        return $total;
    }

    // Get current date in MySQL format
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
    $firstDayThisMonth = date('Y-m-01');
    $firstDayLastMonth = date('Y-m-01', strtotime('-1 month'));
    $lastDayLastMonth = date('Y-m-t', strtotime('-1 month'));
    $firstDayThisYear = date('Y-01-01');
    $firstDayLastYear = date('Y-01-01', strtotime('-1 year'));
    $lastDayLastYear = date('Y-12-31', strtotime('-1 year'));

    // Get transaction sums
    $todaySum = getTransactionSum($today . ' 00:00:00', $today . ' 23:59:59');
    $yesterdaySum = getTransactionSum($yesterday . ' 00:00:00', $yesterday . ' 23:59:59');
    $last7DaysSum = getTransactionSum($sevenDaysAgo . ' 00:00:00', $today . ' 23:59:59');
    $thisMonthSum = getTransactionSum($firstDayThisMonth . ' 00:00:00', $today . ' 23:59:59');
    $lastMonthSum = getTransactionSum($firstDayLastMonth . ' 00:00:00', $lastDayLastMonth . ' 23:59:59');
    $thisYearSum = getTransactionSum($firstDayThisYear . ' 00:00:00', $today . ' 23:59:59');
    $lastYearSum = getTransactionSum($firstDayLastYear . ' 00:00:00', $lastDayLastYear . ' 23:59:59');
    
    // Get all time sum
    $allTimeQuery = "SELECT transaction_amount, transaction_currency 
                    FROM " . $db_prefix . "transaction 
                    WHERE transaction_status = 'completed'";
    $allTimeResult = $conn->query($allTimeQuery);
    
    $allTimeSum = 0;
    while ($row = $allTimeResult->fetch_assoc()) {
        $amount = floatval($row['transaction_amount']);
        $currency = $row['transaction_currency'];
        $bdtAmount = convertCurrency($amount, $currency);
        $allTimeSum += $bdtAmount;
    }

          // Format numbers with proper currency symbol and code
      function formatAmount($amount) {
          global $setting;
          $symbol = $setting['response'][0]['currency_symbol'];
          $code = $setting['response'][0]['default_currency'];
          
          // Handle large numbers properly
          if ($amount >= 10000000) { // 1 crore or more
              $formatted = number_format($amount / 10000000, 2) . ' Cr';
          } else {
              $formatted = number_format($amount, 2);
          }
          
          return $symbol . ' ' . $formatted . ' ' . $code;
      }

      function getPaymentMethods($category){
        global $conn, $db_prefix;
        $query = "SELECT * FROM " . $db_prefix . "plugins WHERE status = 'active' AND plugin_dir = 'payment-gateway' AND JSON_EXTRACT(plugin_array, '$.category') = '".$category."'";
        $result = $conn->query($query);
        $methods = [];
        while ($row = $result->fetch_assoc()) {
          $methods[] = $row;
        }
        return $methods;
      }

      function getMethodByPaymentSum($payment_method_id, $startDate, $endDate){
        global $conn, $db_prefix;
        $query = "SELECT transaction_amount, transaction_currency 
                 FROM " . $db_prefix . "transaction 
                 WHERE transaction_status = 'completed' 
                 AND payment_method_id = ? 
                 AND created_at BETWEEN ? AND ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sss", $payment_method_id, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $amount = floatval($row['transaction_amount']);
            $currency = $row['transaction_currency'];
            $bdtAmount = convertCurrency($amount, $currency);
            $total += $bdtAmount;
        }
        
        return $total;
      }
?>

<div class="d-flex flex-column gap-4">
    <!-- Page Header -->
    <div class="page-header">
      <div class="row align-items-end">
        <div class="col-sm mb-2 mb-sm-0 d-flex align-items-center gap-2">
          <h1 class="page-header-title" style="margin-bottom: 4px;">Advance Analytics</h1>
          <span class="badge bg-success" style="height: fit-content;">Completed Payments</span>
        </div>
        <div class="col-sm-auto">
          <button type="button" class="btn btn-primary" id="refreshData">
            <i class="bi bi-arrow-clockwise"></i> Refresh
          </button>
        </div>
      </div>
    </div>

    <div class="row justify-content-center">
      <div>
        <div class="d-grid gap-3 gap-lg-5">
          <!-- Card -->
          <div class="card">
            <div class="card-header d-flex gap-2 align-items-center">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-receipt" viewBox="0 0 16 16">
                <path d="M1.92.506a.5.5 0 0 1 .434.14L3 1.293l.646-.647a.5.5 0 0 1 .708 0L5 1.293l.646-.647a.5.5 0 0 1 .708 0L7 1.293l.646-.647a.5.5 0 0 1 .708 0L9 1.293l.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .801.13l.5 1A.5.5 0 0 1 15 2v12a.5.5 0 0 1-.053.224l-.5 1a.5.5 0 0 1-.8.13L13 14.707l-.646.647a.5.5 0 0 1-.708 0L11 14.707l-.646.647a.5.5 0 0 1-.708 0L9 14.707l-.646.647a.5.5 0 0 1-.708 0L7 14.707l-.646.647a.5.5 0 0 1-.708 0L5 14.707l-.646.647a.5.5 0 0 1-.708 0L3 14.707l-.646.647a.5.5 0 0 1-.801-.13l-.5-1A.5.5 0 0 1 1 14V2a.5.5 0 0 1 .053-.224l.5-1a.5.5 0 0 1 .367-.27zm.217 1.338L2 2.118v11.764l.137.274.51-.51a.5.5 0 0 1 .707 0l.646.647.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .708 0l.509.509.137-.274V2.118l-.137-.274-.51.51a.5.5 0 0 1-.707 0L12 1.707l-.646.647a.5.5 0 0 1-.708 0L10 1.707l-.646.647a.5.5 0 0 1-.708 0L8 1.707l-.646.647a.5.5 0 0 1-.708 0L6 1.707l-.646.647a.5.5 0 0 1-.708 0L4 1.707l-.646.647a.5.5 0 0 1-.708 0l-.509-.51z"/>
                <path d="M3 4.5a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5zm8-6a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5z"/>
              </svg>
              <h2 class="card-title h4" style="margin-bottom: 0;">
                All Payments Statistics
              </h2> 
            </div>

            <!-- Body -->
            <div class="row g-3 p-3">
            <!-- Today's Revenue -->
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2">Today</h6>
                        <div class="row align-items-center gx-2">
                            <div class="col">
                                <span class="h2 d-block"><?php echo formatAmount($todaySum); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End Today's Revenue -->

            <!-- Yesterday's Revenue -->
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2">YESTERDAY</h6>
                        <div class="row align-items-center gx-2">
                            <div class="col">
                                <span class="h2 d-block"><?php echo formatAmount($yesterdaySum); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End Yesterday's Revenue -->

            <!-- Last 7 Days Revenue -->
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2">LAST 7 DAYS</h6>
                        <div class="row align-items-center gx-2">
                            <div class="col">
                                <span class="h2 d-block"><?php echo formatAmount($last7DaysSum); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End Last 7 Days Revenue -->

            <!-- This Month Revenue -->
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2">THIS MONTH</h6>
                        <div class="row align-items-center gx-2">
                            <div class="col">
                                <span class="h2 d-block"><?php echo formatAmount($thisMonthSum); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End This Month Revenue -->

            <!-- Last Month Revenue -->
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2">LAST MONTH</h6>
                        <div class="row align-items-center gx-2">
                            <div class="col">
                                <span class="h2 d-block"><?php echo formatAmount($lastMonthSum); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End Last Month Revenue -->

            <!-- This Year Revenue -->
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2">THIS YEAR</h6>
                        <div class="row align-items-center gx-2">
                            <div class="col">
                                <span class="h2 d-block"><?php echo formatAmount($thisYearSum); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End This Year Revenue -->

            <!-- Last Year Revenue -->
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2">LAST YEAR</h6>
                        <div class="row align-items-center gx-2">
                            <div class="col">
                                <span class="h2 d-block"><?php echo formatAmount($lastYearSum); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End Last Year Revenue -->

            <!-- All Time Revenue -->
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2">ALL TIME</h6>
                        <div class="row align-items-center gx-2">
                            <div class="col">
                                <span class="h2 d-block"><?php echo formatAmount($allTimeSum); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End All Time Revenue -->
            </div>
          </div>
      </div>
    </div>
    </div>

    <div class="row justify-content-center">
      <div>
        <div class="d-grid gap-3 gap-lg-5">
          <!-- Card -->
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <div class="d-flex gap-2 align-items-center">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-phone" viewBox="0 
              0 16 16">
                <path d="M11 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h6zM5 0a2 2 0 0 0-2 2v12a2 2 0 0 0 
                2 2h6a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H5z"/>
                <path d="M8 14a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
              </svg>
                <h2 class="card-title h4 mb-0">
                  Mobile Banking Payment Statistics
                </h2>
              </div>
              <div class="nav-item dropdown">
                  <a class="nav-link dropdown-toggle d-flex align-items-center gap-1" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-funnel" viewBox="0 0 16 16">
                        <path d="M1.5 1.5A.5.5 0 0 1 2 1h12a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-.128.334L10 8.692V13.5a.5.5 0 0 1-.342.474l-3 1A.5.5 0 0 1 6 14.5V8.692L1.628 3.834A.5.5 0 0 1 1.5 3.5v-2zm1 .5v1.308l4.372 4.858A.5.5 0 0 1 7 8.5v5.306l2-.666V8.5a.5.5 0 0 1 .128-.334L13.5 3.308V2h-11z"/>
                      </svg>
                      Filter
                  </a>
                  <ul class="dropdown-menu">
                      <li><a class="dropdown-item mb-dropdown active" data-bs-toggle="tab" href="#mb_today">Today</a></li>
                      <li><a class="dropdown-item mb-dropdown" data-bs-toggle="tab" href="#mb_yesterday">Yesterday</a></li>
                      <li><a class="dropdown-item mb-dropdown" data-bs-toggle="tab" href="#mb_this_week">Last 7 Days</a></li>
                      <li><a class="dropdown-item mb-dropdown" data-bs-toggle="tab" href="#mb_this_month">This Month</a></li>
                      <li><a class="dropdown-item mb-dropdown" data-bs-toggle="tab" href="#mb_last_month">Last Month</a></li>
                      <li><a class="dropdown-item mb-dropdown" data-bs-toggle="tab" href="#mb_this_year">This Year</a></li>
                      <li><a class="dropdown-item mb-dropdown" data-bs-toggle="tab" href="#mb_last_year">Last Year</a></li>
                  </ul>
              </div>
            </div>

            <div class="tab-content">
              <!-- Today's Tab -->
              <div class="tab-pane mb-tab-pane fade show active" id="mb_today">
                <div class="row g-3 p-3">
                  <?php 
                    $methods = getPaymentMethods('Mobile Banking');
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $today . ' 00:00:00', $today . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    } else { 
                  ?>
                    <div class="col-12">
                      <div class="card h-100">
                        <div class="card-body text-center">
                          <p class="mb-0">No active mobile banking payment methods found.</p>
                        </div>
                      </div>
                    </div>
                  <?php } ?>
                </div>
              </div>

              <!-- Yesterday's Tab -->
              <div class="tab-pane mb-tab-pane fade" id="mb_yesterday">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $yesterday . ' 00:00:00', $yesterday . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- Last 7 Days Tab -->
              <div class="tab-pane mb-tab-pane fade" id="mb_this_week">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $sevenDaysAgo . ' 00:00:00', $today . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- This Month Tab -->
              <div class="tab-pane mb-tab-pane fade" id="mb_this_month">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $firstDayThisMonth . ' 00:00:00', $today . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- Last Month Tab -->
              <div class="tab-pane mb-tab-pane fade" id="mb_last_month">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $firstDayLastMonth . ' 00:00:00', $lastDayLastMonth . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- This Year Tab -->
              <div class="tab-pane mb-tab-pane fade" id="mb_this_year">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $firstDayThisYear . ' 00:00:00', $today . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- Last Year Tab -->
              <div class="tab-pane mb-tab-pane fade" id="mb_last_year">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $firstDayLastYear . ' 00:00:00', $lastDayLastYear . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>
            </div>
            
          </div>
      </div>
    </div>
    </div>

    <div class="row justify-content-center">
      <div>
        <div class="d-grid gap-3 gap-lg-5">
          <!-- Card -->
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <div class="d-flex gap-2 align-items-center">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-phone" viewBox="0 
              0 16 16">
                <path d="M11 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h6zM5 0a2 2 0 0 0-2 2v12a2 2 0 0 0 
                2 2h6a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H5z"/>
                <path d="M8 14a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
              </svg>
                <h2 class="card-title h4 mb-0">
                  IBanking Payment Statistics
                </h2>
              </div>
              <div class="nav-item dropdown">
                  <a class="nav-link dropdown-toggle d-flex align-items-center gap-1" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-funnel" viewBox="0 0 16 16">
                        <path d="M1.5 1.5A.5.5 0 0 1 2 1h12a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-.128.334L10 8.692V13.5a.5.5 0 0 1-.342.474l-3 1A.5.5 0 0 1 6 14.5V8.692L1.628 3.834A.5.5 0 0 1 1.5 3.5v-2zm1 .5v1.308l4.372 4.858A.5.5 0 0 1 7 8.5v5.306l2-.666V8.5a.5.5 0 0 1 .128-.334L13.5 3.308V2h-11z"/>
                      </svg>
                      Filter
                  </a>
                  <ul class="dropdown-menu">
                      <li><a class="dropdown-item ib-dropdown active" data-bs-toggle="tab" href="#ib_today">Today</a></li>
                      <li><a class="dropdown-item ib-dropdown" data-bs-toggle="tab" href="#ib_yesterday">Yesterday</a></li>
                      <li><a class="dropdown-item ib-dropdown" data-bs-toggle="tab" href="#ib_this_week">Last 7 Days</a></li>
                      <li><a class="dropdown-item ib-dropdown" data-bs-toggle="tab" href="#ib_this_month">This Month</a></li>
                      <li><a class="dropdown-item ib-dropdown" data-bs-toggle="tab" href="#ib_last_month">Last Month</a></li>
                      <li><a class="dropdown-item ib-dropdown" data-bs-toggle="tab" href="#ib_this_year">This Year</a></li>
                      <li><a class="dropdown-item ib-dropdown" data-bs-toggle="tab" href="#ib_last_year">Last Year</a></li>
                  </ul>
              </div>
            </div>

            <div class="tab-content">
              <!-- Today's Tab -->
              <div class="tab-pane ib-tab-pane fade show active" id="ib_today">
                <div class="row g-3 p-3">
                  <?php 
                    $methods = getPaymentMethods('IBanking');
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $today . ' 00:00:00', $today . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    } else { 
                  ?>
                    <div class="col-12">
                      <div class="card h-100">
                        <div class="card-body text-center">
                          <p class="mb-0">No active mobile banking payment methods found.</p>
                        </div>
                      </div>
                    </div>
                  <?php } ?>
                </div>
              </div>

              <!-- Yesterday's Tab -->
              <div class="tab-pane ib-tab-pane fade" id="ib_yesterday">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $yesterday . ' 00:00:00', $yesterday . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- Last 7 Days Tab -->
              <div class="tab-pane ib-tab-pane fade" id="ib_this_week">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $sevenDaysAgo . ' 00:00:00', $today . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- This Month Tab -->
              <div class="tab-pane ib-tab-pane fade" id="ib_this_month">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $firstDayThisMonth . ' 00:00:00', $today . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- Last Month Tab -->
              <div class="tab-pane ib-tab-pane fade" id="ib_last_month">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $firstDayLastMonth . ' 00:00:00', $lastDayLastMonth . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- This Year Tab -->
              <div class="tab-pane ib-tab-pane fade" id="ib_this_year">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $firstDayThisYear . ' 00:00:00', $today . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- Last Year Tab -->
              <div class="tab-pane ib-tab-pane fade" id="ib_last_year">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $firstDayLastYear . ' 00:00:00', $lastDayLastYear . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>
            </div>
            
          </div>
      </div>
    </div>
    </div>

    <div class="row justify-content-center">
      <div>
        <div class="d-grid gap-3 gap-lg-5">
          <!-- Card -->
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <div class="d-flex gap-2 align-items-center">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-phone" viewBox="0 
              0 16 16">
                <path d="M11 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h6zM5 0a2 2 0 0 0-2 2v12a2 2 0 0 0 
                2 2h6a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H5z"/>
                <path d="M8 14a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
              </svg>
                <h2 class="card-title h4 mb-0">
                  IBanking Payment Statistics
                </h2>
              </div>
              <div class="nav-item dropdown">
                  <a class="nav-link dropdown-toggle d-flex align-items-center gap-1" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-funnel" viewBox="0 0 16 16">
                        <path d="M1.5 1.5A.5.5 0 0 1 2 1h12a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-.128.334L10 8.692V13.5a.5.5 0 0 1-.342.474l-3 1A.5.5 0 0 1 6 14.5V8.692L1.628 3.834A.5.5 0 0 1 1.5 3.5v-2zm1 .5v1.308l4.372 4.858A.5.5 0 0 1 7 8.5v5.306l2-.666V8.5a.5.5 0 0 1 .128-.334L13.5 3.308V2h-11z"/>
                      </svg>
                      Filter
                  </a>
                  <ul class="dropdown-menu">
                      <li><a class="dropdown-item int-dropdown active" data-bs-toggle="tab" href="#int_today">Today</a></li>
                      <li><a class="dropdown-item int-dropdown" data-bs-toggle="tab" href="#int_yesterday">Yesterday</a></li>
                      <li><a class="dropdown-item int-dropdown" data-bs-toggle="tab" href="#int_this_week">Last 7 Days</a></li>
                      <li><a class="dropdown-item int-dropdown" data-bs-toggle="tab" href="#int_this_month">This Month</a></li>
                      <li><a class="dropdown-item int-dropdown" data-bs-toggle="tab" href="#int_last_month">Last Month</a></li>
                      <li><a class="dropdown-item int-dropdown" data-bs-toggle="tab" href="#int_this_year">This Year</a></li>
                      <li><a class="dropdown-item int-dropdown" data-bs-toggle="tab" href="#int_last_year">Last Year</a></li>
                  </ul>
              </div>
            </div>

            <div class="tab-content">
              <!-- Today's Tab -->
              <div class="tab-pane int-tab-pane fade show active" id="int_today">
                <div class="row g-3 p-3">
                  <?php 
                    $methods = getPaymentMethods('International');
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $today . ' 00:00:00', $today . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    } else { 
                  ?>
                    <div class="col-12">
                      <div class="card h-100">
                        <div class="card-body text-center">
                          <p class="mb-0">No active mobile banking payment methods found.</p>
                        </div>
                      </div>
                    </div>
                  <?php } ?>
                </div>
              </div>

              <!-- Yesterday's Tab -->
              <div class="tab-pane int-tab-pane fade" id="int_yesterday">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $yesterday . ' 00:00:00', $yesterday . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- Last 7 Days Tab -->
              <div class="tab-pane int-tab-pane fade" id="int_this_week">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $sevenDaysAgo . ' 00:00:00', $today . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- This Month Tab -->
              <div class="tab-pane int-tab-pane fade" id="int_this_month">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $firstDayThisMonth . ' 00:00:00', $today . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- Last Month Tab -->
              <div class="tab-pane int-tab-pane fade" id="int_last_month">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $firstDayLastMonth . ' 00:00:00', $lastDayLastMonth . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- This Year Tab -->
              <div class="tab-pane int-tab-pane fade" id="int_this_year">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $firstDayThisYear . ' 00:00:00', $today . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>

              <!-- Last Year Tab -->
              <div class="tab-pane int-tab-pane fade" id="int_last_year">
                <div class="row g-3 p-3">
                  <?php 
                    if (!empty($methods)) {
                      foreach ($methods as $method) {
                        $methodSum = getMethodByPaymentSum($method['plugin_slug'], $firstDayLastYear . ' 00:00:00', $lastDayLastYear . ' 23:59:59');
                  ?>
                    <div class="col-sm-6 col-lg-3">
                      <div class="card h-100">
                        <div class="card-body">
                          <h6 class="card-subtitle mb-2"><?php echo $method['plugin_name']; ?></h6>
                          <div class="row align-items-center gx-2">
                            <div class="col">
                              <span class="h2 d-block"><?php echo formatAmount($methodSum); ?></span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php 
                      }
                    }
                  ?>
                </div>
              </div>
            </div>
            
          </div>
      </div>
    </div>
    </div>
</div>

<script>
// Handle refresh button click
document.getElementById('refreshData').addEventListener('click', function() {
    // Add loading state
    const button = this;
    const originalContent = button.innerHTML;
    button.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Loading...';
    button.disabled = true;
    
    // Reload the page
    window.location.reload();
});

// Handle filter changes
document.querySelectorAll('.mb-dropdown').forEach(item => {
    item.addEventListener('click', function(e) {
        e.preventDefault();
        
        // Remove active class from all items
        document.querySelectorAll('.mb-dropdown').forEach(i => {
            i.classList.remove('active');
        });
        
        // Add active class to clicked item
        this.classList.add('active');
        
        // Show the selected tab
        const tabId = this.getAttribute('href');
        document.querySelectorAll('.mb-tab-pane').forEach(pane => {
            pane.classList.remove('show', 'active');
        });
        document.querySelector(tabId).classList.add('show', 'active');
    });
});

document.querySelectorAll('.ib-dropdown').forEach(item => {
    item.addEventListener('click', function(e) {
        e.preventDefault();
        
        // Remove active class from all items
        document.querySelectorAll('.ib-dropdown').forEach(i => {
            i.classList.remove('active');
        });

        // Add active class to clicked item
        this.classList.add('active');
        
        // Show the selected tab
        const tabId = this.getAttribute('href');
        document.querySelectorAll('.ib-tab-pane').forEach(pane => {
            pane.classList.remove('show', 'active');
        });
        document.querySelector(tabId).classList.add('show', 'active');
    });
});

document.querySelectorAll('.int-dropdown').forEach(item => {
    item.addEventListener('click', function(e) {
        e.preventDefault();
        
        // Remove active class from all items
        document.querySelectorAll('.int-dropdown').forEach(i => {
            i.classList.remove('active');
        });

        // Add active class to clicked item
        this.classList.add('active');
        
        // Show the selected tab
        const tabId = this.getAttribute('href');
        document.querySelectorAll('.int-tab-pane').forEach(pane => {
            pane.classList.remove('show', 'active');
        });
        document.querySelector(tabId).classList.add('show', 'active');
    });
});

</script>