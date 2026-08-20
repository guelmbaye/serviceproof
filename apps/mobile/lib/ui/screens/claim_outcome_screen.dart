import 'package:flutter/material.dart';

import '../../models/models.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// What the network concluded, written for the person who did the work.
///
/// Note what is absent: no evidence items, no request ids, no API names, no
/// agent trace. The backend will not return them to a field worker, and it
/// should not — a technician is entitled to the outcome of their own claim,
/// not to the telecom signals about their own movements. The operations
/// console is where that detail lives.
class ClaimOutcomeScreen extends StatelessWidget {
  const ClaimOutcomeScreen({super.key, required this.claim});

  final Claim claim;

  @override
  Widget build(BuildContext context) {
    final state = claim.decisionState;
    final color = state != null ? SpColors.forState(state) : SpColors.signal;

    return Scaffold(
      appBar: AppBar(title: Text(claim.reference)),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
          children: <Widget>[
            SpPanel(
              accent: color,
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  const Eyebrow('Outcome'),
                  const SizedBox(height: 12),
                  if (state != null)
                    Text(
                      state.label,
                      style: TextStyle(
                        fontSize: 30,
                        height: 1.05,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -0.6,
                        color: color,
                      ),
                    )
                  else
                    const Text(
                      'Being checked',
                      style: TextStyle(
                        fontSize: 30,
                        height: 1.05,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -0.6,
                        color: SpColors.signal,
                      ),
                    ),
                  const SizedBox(height: 14),
                  Text(
                    state?.explanation ??
                        'Your claim reached operations. The network check runs next, and '
                            'this screen will show the result.',
                    style: const TextStyle(fontSize: 16, height: 1.5),
                  ),
                ],
              ),
            ),

            const SizedBox(height: 12),

            SpPanel(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  const Eyebrow('Your claim'),
                  const SizedBox(height: 8),
                  InfoRow('Reference', claim.reference, mono: true),
                  InfoRow('Job', claim.workOrderReference ?? '—', mono: true),
                  InfoRow('Site', claim.siteName ?? '—'),
                  InfoRow('Completed', formatWhen(claim.claimedAt), mono: true),
                  if (claim.notes != null && claim.notes!.isNotEmpty) ...<Widget>[
                    const SizedBox(height: 6),
                    const Divider(),
                    const SizedBox(height: 10),
                    const Eyebrow('Your note'),
                    const SizedBox(height: 6),
                    Text(
                      claim.notes!,
                      style: const TextStyle(fontSize: 15, height: 1.45),
                    ),
                  ],
                ],
              ),
            ),

            if (state == DecisionState.disputed || state == DecisionState.partial) ...<Widget>[
              const SizedBox(height: 12),
              SpPanel(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: const <Widget>[
                    Eyebrow('What happens now'),
                    SizedBox(height: 8),
                    Text(
                      'Someone in operations reviews the job and decides. If they need '
                      'anything from you they will get in touch. There is nothing to '
                      'resubmit and nothing to fix on your side.',
                      style: TextStyle(fontSize: 15, height: 1.45, color: SpColors.ink2),
                    ),
                  ],
                ),
              ),
            ],

            const SizedBox(height: 20),
            OutlinedButton(
              onPressed: () => Navigator.of(context).popUntil((route) => route.isFirst),
              child: const Text('Back to my jobs'),
            ),
          ],
        ),
      ),
    );
  }
}
