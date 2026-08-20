import 'package:flutter/material.dart';

import 'core/api_client.dart';
import 'core/store.dart';
import 'state/jobs_controller.dart';
import 'state/outbox_controller.dart';
import 'state/session_controller.dart';
import 'ui/screens/jobs_screen.dart';
import 'ui/screens/sign_in_screen.dart';
import 'ui/theme.dart';

class ServiceProofFieldApp extends StatefulWidget {
  const ServiceProofFieldApp({super.key, required this.store});

  final Store store;

  @override
  State<ServiceProofFieldApp> createState() => _ServiceProofFieldAppState();
}

class _ServiceProofFieldAppState extends State<ServiceProofFieldApp>
    with WidgetsBindingObserver {
  late final ApiClient _api = ApiClient();
  late final SessionController _session =
      SessionController(api: _api, store: widget.store);
  late final OutboxController _outbox =
      OutboxController(api: _api, store: widget.store);
  late final JobsController _jobs = JobsController(_api);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);

    _outbox.load();
    _session.restore();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _api.close();
    super.dispose();
  }

  /// Coming back to the app is the most likely moment for signal to have
  /// returned, so that is when the outbox tries again.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed &&
        _session.status == SessionStatus.signedIn) {
      _outbox.flush();
    }
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'ServiceProof Field',
      debugShowCheckedModeBanner: false,
      theme: buildTheme(),
      home: ListenableBuilder(
        listenable: _session,
        builder: (BuildContext context, _) {
          return switch (_session.status) {
            SessionStatus.starting => const Scaffold(
                body: Center(child: CircularProgressIndicator()),
              ),
            SessionStatus.signedOut => SignInScreen(session: _session),
            SessionStatus.signedIn => JobsScreen(
                session: _session,
                jobs: _jobs,
                outbox: _outbox,
              ),
          };
        },
      ),
    );
  }
}
