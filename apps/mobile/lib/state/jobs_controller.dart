import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../data/repositories.dart';
import '../models/models.dart';

/// The technician's job list and the outcomes of their own claims.
class JobsController extends ChangeNotifier {
  JobsController(ApiClient api)
      : _workOrders = WorkOrderRepository(api),
        _claims = ClaimRepository(api);

  final WorkOrderRepository _workOrders;
  final ClaimRepository _claims;

  List<WorkOrder> jobs = <WorkOrder>[];
  List<Claim> claims = <Claim>[];
  bool loading = false;
  bool offline = false;
  String? error;
  DateTime? lastLoadedAt;

  Claim? claimFor(String workOrderReference) {
    for (final claim in claims) {
      if (claim.workOrderReference == workOrderReference) return claim;
    }
    return null;
  }

  Future<void> refresh() async {
    loading = true;
    error = null;
    notifyListeners();

    try {
      final results = await Future.wait(<Future<Object>>[
        _workOrders.assignedToMe(),
        _claims.mine(),
      ]);

      jobs = results[0] as List<WorkOrder>;
      claims = results[1] as List<Claim>;
      offline = false;
      lastLoadedAt = DateTime.now();
    } on Offline {
      // Keep whatever is on screen. A stale list beats an empty one when
      // you are standing in front of the cabinet you came to fix.
      offline = true;
    } on ApiException catch (api) {
      error = api.message;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  Future<WorkOrder?> load(String id) async {
    try {
      return await _workOrders.find(id);
    } on Offline {
      offline = true;
      notifyListeners();
      return null;
    }
  }
}
