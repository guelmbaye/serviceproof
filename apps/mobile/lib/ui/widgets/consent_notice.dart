import 'package:flutter/material.dart';

import '../theme.dart';
import 'common.dart';

/// What the network will be asked, told to the person it will be asked about.
///
/// This screen exists because the technician is the data subject here. They
/// are entitled to know, in their own language and before they act, that
/// submitting a claim causes their operator to be queried about their
/// device — and to know the limits of that query.
///
/// The backend cannot enforce a lawful basis on its own. Capturing consent
/// properly, per jurisdiction, is named as unbuilt work in the docs. This
/// notice is the honest minimum, not the finished answer.
class ConsentNotice extends StatelessWidget {
  const ConsentNotice({super.key, this.deviceReference, this.compact = false});

  final String? deviceReference;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return SpPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          const Eyebrow('What gets checked', color: SpColors.signal),
          const SizedBox(height: 8),
          const Text(
            'When you submit this claim, ServiceProof asks your mobile operator two '
            'things about the work phone: whether it was inside the site area, and '
            'whether it was connected.',
            style: TextStyle(fontSize: 15, height: 1.45),
          ),
          if (!compact) ...<Widget>[
            const SizedBox(height: 12),
            const _Limit('It is a yes or no answer about the site area — not a map, '
                'and not a history of where you have been.'),
            const _Limit('It is asked once, for this job, at the time you submit.'),
            const _Limit('Your name is never sent. The system sees a worker reference '
                'and a device, nothing else.'),
            const _Limit('This app never sends its own GPS. Your phone is not asked '
                'to prove anything about itself.'),
          ],
          if (deviceReference != null) ...<Widget>[
            const SizedBox(height: 12),
            Text('Device on this job: $deviceReference', style: kMono),
          ],
        ],
      ),
    );
  }
}

class _Limit extends StatelessWidget {
  const _Limit(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 7),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Container(
            width: 5,
            height: 5,
            margin: const EdgeInsets.only(top: 8, right: 10),
            decoration: const BoxDecoration(
              color: SpColors.ink3,
              shape: BoxShape.circle,
            ),
          ),
          Expanded(
            child: Text(
              text,
              style: const TextStyle(fontSize: 14, color: SpColors.ink2, height: 1.4),
            ),
          ),
        ],
      ),
    );
  }
}
