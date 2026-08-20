import 'dart:async';

import 'package:flutter/material.dart';

import '../../models/models.dart';
import '../../state/jobs_controller.dart';
import '../../state/outbox_controller.dart';
import '../../state/session_controller.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/outbox_banner.dart';
import 'job_detail_screen.dart';

/// Today's work. The first screen a technician sees, and usually the only
/// one they need.
class JobsScreen extends StatefulWidget {
  const JobsScreen({
    super.key,
    required this.session,
    required this.jobs,
    required this.outbox,
  });

  final SessionController session;
  final JobsController jobs;
  final OutboxController outbox;

  @override
  State<JobsScreen> createState() => _JobsScreenState();
}

class _JobsScreenState extends State<JobsScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      unawaited(widget.jobs.refresh());
      unawaited(widget.outbox.flush());
    });
  }

  Future<void> _refresh() async {
    await widget.outbox.flush();
    await widget.jobs.refresh();
  }

  @override
  Widget build(BuildContext context) {
    final user = widget.session.user;

    return Scaffold(
      appBar: AppBar(
        title: const Text('My jobs'),
        actions: <Widget>[
          IconButton(
            onPressed: () => widget.session.signOut(),
            icon: const Icon(Icons.logout, size: 20),
            tooltip: 'Sign out',
          ),
        ],
      ),
      body: SafeArea(
        child: ListenableBuilder(
          listenable: Listenable.merge(<Listenable>[widget.jobs, widget.outbox]),
          builder: (BuildContext context, _) {
            final jobs = widget.jobs.jobs;

            return RefreshIndicator(
              onRefresh: _refresh,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
                children: <Widget>[
                  if (user != null) ...<Widget>[
                    Text(
                      user.name,
                      style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '${user.employeeReference ?? "—"} · ${user.organizationName ?? ""}',
                      style: kMono,
                    ),
                    const SizedBox(height: 18),
                  ],
                  if (widget.jobs.offline)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: SpPanel(
                        accent: SpColors.unverified,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: const <Widget>[
                            Eyebrow('No signal'),
                            SizedBox(height: 6),
                            Text(
                              'Showing the jobs already on this phone. Pull down to try again.',
                              style: TextStyle(
                                fontSize: 14,
                                color: SpColors.ink2,
                                height: 1.4,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  OutboxBanner(outbox: widget.outbox),
                  if (widget.jobs.loading && jobs.isEmpty)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 48),
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else if (jobs.isEmpty)
                    const EmptyState(
                      headline: 'Nothing assigned to you right now.',
                      hint: 'Pull down to check again.',
                    )
                  else
                    for (final WorkOrder job in jobs)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: _JobCard(
                          job: job,
                          claim: widget.jobs.claimFor(job.reference),
                          queued: widget.outbox.hasPendingFor(job.id),
                          onOpen: () => _open(job),
                        ),
                      ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Future<void> _open(WorkOrder job) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (BuildContext context) => JobDetailScreen(
          job: job,
          jobs: widget.jobs,
          outbox: widget.outbox,
        ),
      ),
    );

    await _refresh();
  }
}

class _JobCard extends StatelessWidget {
  const _JobCard({
    required this.job,
    required this.claim,
    required this.queued,
    required this.onOpen,
  });

  final WorkOrder job;
  final Claim? claim;
  final bool queued;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final decision = claim?.decisionState;

    return InkWell(
      onTap: onOpen,
      borderRadius: BorderRadius.circular(3),
      child: SpPanel(
        accent: decision != null ? SpColors.forState(decision) : null,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Expanded(
                  child: Text(
                    job.reference,
                    style: const TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.w700,
                      letterSpacing: -0.3,
                    ),
                  ),
                ),
                if (job.isHighRisk) const Eyebrow('High risk', color: SpColors.partial),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              job.customer ?? '—',
              style: const TextStyle(fontSize: 15, color: SpColors.ink2),
            ),
            const SizedBox(height: 2),
            Text(job.site.name ?? '—', style: const TextStyle(fontSize: 14)),
            const SizedBox(height: 12),
            Row(
              children: <Widget>[
                Text(formatWhen(job.scheduledAt), style: kMono),
                const Spacer(),
                if (queued)
                  const Eyebrow('Waiting to send', color: SpColors.partial)
                else if (decision != null)
                  StateBadge(decision)
                else if (claim != null)
                  const Eyebrow('Submitted')
                else
                  const Eyebrow('Not submitted', color: SpColors.signal),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
